<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\Plan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Laravel\Paddle\Customer;
use Laravel\Paddle\Subscription;
use Tests\TestCase;

/**
 * Phase 0's remaining item: the webhook endpoint is reachable by Paddle and by
 * nobody else.
 *
 * Everything downstream of entitlements depends on this endpoint being the only
 * way a subscription row appears. If it accepted unsigned payloads, anyone
 * could grant themselves Pro with a single POST.
 */
final class PaddleWebhookTest extends TestCase
{
    use RefreshDatabase;

    private const string SECRET = 'pdl_ntfset_test_secret';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'cashier.webhook_secret' => self::SECRET,
            'plans.prices' => ['pro' => ['monthly' => 'pri_pro_monthly']],
        ]);
    }

    public function test_the_webhook_route_is_registered(): void
    {
        $this->assertTrue(Route::has('cashier.webhook'));
        $this->assertSame('/paddle/webhook', route('cashier.webhook', absolute: false));
    }

    /**
     * Paddle posts without a session or a CSRF token, so the endpoint must sit
     * outside the `web` group. Were it inside, every real webhook would be
     * rejected with a 419 and no subscription would ever land.
     */
    public function test_the_webhook_route_is_exempt_from_csrf(): void
    {
        $middleware = collect(Route::getRoutes()->getByName('cashier.webhook')->gatherMiddleware());

        $this->assertFalse(
            $middleware->contains(fn (mixed $name): bool => is_string($name)
                && str_contains(mb_strtolower($name), 'csrf')),
            'The Paddle webhook must not run behind CSRF verification.',
        );
    }

    public function test_an_unsigned_payload_is_rejected(): void
    {
        $this->postJson(route('cashier.webhook'), $this->subscriptionCreatedPayload('ctm_x', 'sub_x'))
            ->assertForbidden();

        $this->assertDatabaseCount('subscriptions', 0);
    }

    /**
     * The failure mode that matters most, because it is the one an environment
     * reaches by omission rather than by attack.
     *
     * Cashier applies its signature middleware only when a webhook secret is
     * configured, so an environment that forgets `PADDLE_WEBHOOK_SECRET` would
     * accept every POST to this endpoint and hand Pro to anyone who found the
     * URL. PaddleWebhookController makes the check unconditional: with no
     * secret, nothing can produce a matching signature and everything is
     * refused.
     */
    public function test_a_missing_webhook_secret_rejects_every_call_rather_than_accepting_them(): void
    {
        config(['cashier.webhook_secret' => null]);

        $payload = $this->subscriptionCreatedPayload('ctm_x', 'sub_x');

        $this->postJson(route('cashier.webhook'), $payload)->assertForbidden();

        $this->call(
            'POST',
            route('cashier.webhook'),
            server: $this->signature($payload),
            content: json_encode($payload),
        )->assertForbidden();

        $this->assertDatabaseCount('subscriptions', 0);
    }

    public function test_a_payload_signed_with_the_wrong_secret_is_rejected(): void
    {
        $payload = $this->subscriptionCreatedPayload('ctm_x', 'sub_x');

        $this->call(
            'POST',
            route('cashier.webhook'),
            server: $this->signature($payload, 'pdl_ntfset_someone_elses_secret'),
            content: json_encode($payload),
        )->assertForbidden();

        $this->assertDatabaseCount('subscriptions', 0);
    }

    /**
     * The signature covers the timestamp as well as the body, and Cashier
     * refuses anything more than five seconds old — a captured payload cannot
     * be replayed later.
     */
    public function test_a_correctly_signed_but_stale_payload_is_rejected(): void
    {
        $payload = $this->subscriptionCreatedPayload('ctm_x', 'sub_x');

        $this->call(
            'POST',
            route('cashier.webhook'),
            server: $this->signature($payload, timestamp: time() - 3600),
            content: json_encode($payload),
        )->assertForbidden();
    }

    public function test_a_signed_payload_is_accepted_and_grants_the_plan_it_sells(): void
    {
        $user = User::factory()->create();

        Customer::query()->create([
            'billable_id' => $user->id,
            'billable_type' => $user->getMorphClass(),
            'paddle_id' => 'ctm_signed',
            'name' => $user->name,
            'email' => $user->email,
        ]);

        $payload = $this->subscriptionCreatedPayload('ctm_signed', 'sub_signed');

        $this->call(
            'POST',
            route('cashier.webhook'),
            server: $this->signature($payload),
            content: json_encode($payload),
        )->assertOk();

        $this->assertDatabaseHas('subscriptions', [
            'paddle_id' => 'sub_signed',
            'status' => Subscription::STATUS_ACTIVE,
        ]);

        /*
         * The point of the whole phase: a webhook Paddle sent is the only thing
         * that has to happen for entitlements to flip. Resolution reads the
         * subscription table on the next request, so there is no cache to
         * invalidate and nothing to log out and back in for.
         */
        $this->assertSame(Plan::Pro, $user->fresh()->plan());
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, string>
     */
    private function signature(array $payload, string $secret = self::SECRET, ?int $timestamp = null): array
    {
        $timestamp ??= time();
        $body = json_encode($payload);
        $hash = hash_hmac('sha256', sprintf('%d:%s', $timestamp, $body), $secret);

        return [
            'HTTP_PADDLE_SIGNATURE' => sprintf('ts=%d;h1=%s', $timestamp, $hash),
            'CONTENT_TYPE' => 'application/json',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function subscriptionCreatedPayload(string $customerId, string $subscriptionId): array
    {
        return [
            'event_type' => 'subscription.created',
            'data' => [
                'id' => $subscriptionId,
                'customer_id' => $customerId,
                'status' => Subscription::STATUS_ACTIVE,
                'custom_data' => ['plan' => 'pro', 'variant' => 'monthly'],
                'items' => [[
                    'price' => ['id' => 'pri_pro_monthly', 'product_id' => 'pro_droneverse'],
                    'status' => 'active',
                    'quantity' => 1,
                ]],
            ],
        ];
    }
}
