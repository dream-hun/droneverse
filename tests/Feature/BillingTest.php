<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\Plan;
use App\Http\Middleware\HandleInertiaRequests;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Paddle\Cashier;
use Laravel\Paddle\Subscription;
use Laravel\Paddle\Transaction;
use Tests\TestCase;

final class BillingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'plans.prices' => [
                'pro' => [
                    'monthly' => 'pri_pro_monthly',
                    'yearly' => 'pri_pro_yearly',
                ],
            ],
            'cashier.api_key' => 'pdl_test_key',
        ]);

        /*
         * Nothing on this page may reach Paddle for real. Every test that needs
         * an API answer fakes it explicitly; anything else is a bug worth a
         * failure rather than a network round trip.
         */
        Http::preventStrayRequests();
    }

    public function test_billing_requires_an_account(): void
    {
        $this->get(route('billing.edit'))->assertRedirect(route('login'));
    }

    public function test_a_starter_pilot_sees_a_page_with_nothing_to_bill(): void
    {
        $this->actingAs(User::factory()->create())
            ->get(route('billing.edit'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('settings/billing')
                ->where('plan.value', 'starter')
                ->where('plan.source', 'none')
                ->where('subscription', null)
                ->where('transactions', []));
    }

    /**
     * A comped account holds a plan with no subscription behind it. Telling
     * those pilots "no active subscription" would be alarming and wrong, so the
     * page is driven by `source` instead.
     */
    public function test_a_comped_account_is_reported_as_an_override(): void
    {
        $this->actingAs(User::factory()->onPlan(Plan::Pro)->create())
            ->get(route('billing.edit'))
            ->assertInertia(fn ($page) => $page
                ->where('plan.value', 'pro')
                ->where('plan.source', 'override')
                ->where('subscription', null));
    }

    public function test_a_subscriber_sees_their_plan_and_billing_period(): void
    {
        $user = User::factory()->create();
        $this->subscribe($user, 'pri_pro_yearly');

        $this->actingAs($user)
            ->get(route('billing.edit'))
            ->assertInertia(fn ($page) => $page
                ->where('plan.value', 'pro')
                ->where('plan.source', 'subscription')
                ->where('subscription.planLabel', 'Pro')
                ->where('subscription.variant', 'yearly')
                ->where('subscription.valid', true)
                ->where('subscription.canceled', false));
    }

    /**
     * The next bill date is the one thing on the page only Paddle knows. A page
     * that renders without it is missing a line; a page that 500s because
     * Paddle is unreachable is missing everything.
     */
    public function test_an_unreachable_paddle_costs_the_next_bill_date_and_nothing_else(): void
    {
        config(['cashier.api_key' => null]);

        $user = User::factory()->create();
        $this->subscribe($user, 'pri_pro_monthly');

        $this->actingAs($user)
            ->get(route('billing.edit'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('subscription.nextPayment', null)
                ->where('subscription.valid', true));
    }

    public function test_the_next_bill_date_is_shown_when_paddle_answers(): void
    {
        $user = User::factory()->create();
        $subscription = $this->subscribe($user, 'pri_pro_monthly');

        Http::fake([
            Cashier::apiUrl().'/subscriptions/'.$subscription->paddle_id.'*' => Http::response([
                'data' => [
                    'next_transaction' => [
                        'billing_period' => ['starts_at' => '2026-09-01T00:00:00Z'],
                        'details' => ['totals' => ['grand_total' => '1900', 'currency_code' => 'USD']],
                    ],
                ],
            ]),
        ]);

        $this->actingAs($user)
            ->get(route('billing.edit'))
            ->assertInertia(fn ($page) => $page
                ->where('subscription.nextPayment.amount', '$19.00')
                ->where('subscription.nextPayment.date', '2026-09-01T00:00:00+00:00'));
    }

    public function test_receipts_are_listed_newest_first(): void
    {
        $user = User::factory()->create();

        $this->transaction($user, 'txn_older', '1900', '2026-05-01 10:00:00');
        $this->transaction($user, 'txn_newer', '19000', '2026-06-01 10:00:00');

        $this->actingAs($user)
            ->get(route('billing.edit'))
            ->assertInertia(fn ($page) => $page
                ->where('transactions.0.id', 'txn_newer')
                ->where('transactions.0.total', '$190.00')
                ->where('transactions.1.id', 'txn_older')
                ->where('transactions.1.total', '$19.00'));
    }

    public function test_one_pilots_receipts_do_not_leak_to_another(): void
    {
        $payer = User::factory()->create();
        $this->transaction($payer, 'txn_theirs');

        $this->actingAs(User::factory()->create())
            ->get(route('billing.edit'))
            ->assertInertia(fn ($page) => $page->where('transactions', []));
    }

    public function test_cancelling_schedules_the_end_of_the_paid_period(): void
    {
        $user = User::factory()->create();
        $subscription = $this->subscribe($user, 'pri_pro_monthly');

        Http::fake([
            Cashier::apiUrl().'/subscriptions/'.$subscription->paddle_id.'/cancel' => Http::response([
                'data' => [
                    'status' => Subscription::STATUS_ACTIVE,
                    'scheduled_change' => ['effective_at' => '2026-08-27T00:00:00Z'],
                ],
            ]),
        ]);

        $this->actingAs($user)
            ->delete(route('subscription.destroy'))
            ->assertRedirect(route('billing.edit'));

        $subscription->refresh();

        $this->assertTrue($subscription->onGracePeriod());
        /*
         * The whole point of cancelling at period end: they bought the month,
         * so they keep the catalogue until it runs out.
         */
        $this->assertSame(Plan::Pro, $user->fresh()->plan());
    }

    public function test_resuming_calls_off_a_pending_cancellation(): void
    {
        $user = User::factory()->create();
        $subscription = $this->subscribe($user, 'pri_pro_monthly');
        $subscription->forceFill(['ends_at' => now()->addWeek()])->save();

        Http::fake([
            Cashier::apiUrl().'/subscriptions/'.$subscription->paddle_id => Http::response([
                'data' => ['status' => Subscription::STATUS_ACTIVE],
            ]),
        ]);

        $this->actingAs($user)
            ->put(route('subscription.update'))
            ->assertRedirect(route('billing.edit'));

        $subscription->refresh();

        $this->assertNull($subscription->ends_at);
        $this->assertFalse($subscription->onGracePeriod());
    }

    public function test_resuming_a_subscription_that_has_already_ended_fails_without_calling_paddle(): void
    {
        $user = User::factory()->create();
        $this->subscribe($user, 'pri_pro_monthly');

        $this->actingAs($user)
            ->put(route('subscription.update'))
            ->assertRedirect(route('billing.edit'));

        Http::assertNothingSent();
    }

    public function test_a_starter_pilot_has_no_subscription_to_cancel(): void
    {
        $this->actingAs(User::factory()->create())
            ->delete(route('subscription.destroy'))
            ->assertRedirect(route('billing.edit'));

        Http::assertNothingSent();
    }

    /**
     * There is no subscription identifier anywhere in the request, so there is
     * nothing for one pilot to substitute for another's.
     */
    public function test_cancelling_only_ever_reaches_your_own_subscription(): void
    {
        $subscriber = User::factory()->create();
        $theirs = $this->subscribe($subscriber, 'pri_pro_monthly');

        $this->actingAs(User::factory()->create())
            ->delete(route('subscription.destroy'));

        $this->assertFalse($theirs->refresh()->canceled());
        Http::assertNothingSent();
    }

    public function test_the_payment_method_page_is_paddles_own(): void
    {
        $user = User::factory()->create();
        $subscription = $this->subscribe($user, 'pri_pro_monthly');

        Http::fake([
            Cashier::apiUrl().'/subscriptions/'.$subscription->paddle_id => Http::response([
                'data' => [
                    'management_urls' => ['update_payment_method' => 'https://paddle.test/update'],
                ],
            ]),
        ]);

        $this->actingAs($user)
            ->get(route('payment-method.edit'))
            ->assertRedirect('https://paddle.test/update');
    }

    /**
     * The billing page reaches this route with an Inertia <Link>, which is an
     * XHR. A plain 302 is followed by the browser and answered with Paddle's
     * HTML, which carries no X-Inertia header — Inertia rejects that as an
     * invalid response rather than navigating, so the subscriber can never
     * reach the page. The 409 below is the only answer it acts on.
     */
    public function test_the_payment_method_page_is_reachable_from_an_inertia_visit(): void
    {
        $user = User::factory()->create();
        $subscription = $this->subscribe($user, 'pri_pro_monthly');

        Http::fake([
            Cashier::apiUrl().'/subscriptions/'.$subscription->paddle_id => Http::response([
                'data' => [
                    'management_urls' => ['update_payment_method' => 'https://paddle.test/update'],
                ],
            ]),
        ]);

        /*
         * Resolved through the app's own middleware rather than hardcoded: a
         * version the middleware disagrees with is answered with its own 409
         * pointing back at this page, which would pass a laxer assertion for
         * entirely the wrong reason.
         */
        $version = app(HandleInertiaRequests::class)->version($this->app['request']);

        $this->actingAs($user)
            ->get(route('payment-method.edit'), [
                'X-Inertia' => 'true',
                'X-Inertia-Version' => (string) $version,
            ])
            ->assertStatus(409)
            ->assertHeader('X-Inertia-Location', 'https://paddle.test/update');
    }

    private function subscribe(User $user, string $priceId): Subscription
    {
        $subscription = Subscription::query()->create([
            'billable_id' => $user->id,
            'billable_type' => $user->getMorphClass(),
            'type' => Subscription::DEFAULT_TYPE,
            'paddle_id' => 'sub_'.uniqid(),
            'status' => Subscription::STATUS_ACTIVE,
        ]);

        $subscription->items()->create([
            'product_id' => 'pro_test',
            'price_id' => $priceId,
            'status' => Subscription::STATUS_ACTIVE,
            'quantity' => 1,
        ]);

        return $subscription;
    }

    private function transaction(
        User $user,
        string $paddleId,
        string $total = '1900',
        string $billedAt = '2026-06-01 10:00:00',
    ): Transaction {
        return Transaction::query()->create([
            'billable_id' => $user->id,
            'billable_type' => $user->getMorphClass(),
            'paddle_id' => $paddleId,
            'invoice_number' => 'INV-'.$paddleId,
            'status' => Transaction::STATUS_COMPLETED,
            'total' => $total,
            'tax' => '0',
            'currency' => 'USD',
            'billed_at' => $billedAt,
        ]);
    }
}
