<?php

declare(strict_types=1);

use App\Enums\Plan;
use App\Http\Middleware\VerifyLemonSqueezyWebhookSignature;
use App\Models\User;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Route;
use LemonSqueezy\Laravel\Subscription;

/*
 * The webhook endpoint is reachable by Lemon Squeezy and by nobody else.
 *
 * Everything downstream of entitlements depends on this endpoint being the only
 * way a subscription row appears. If it accepted unsigned payloads, anyone could
 * grant themselves Pro with a single POST.
 *
 * One protection was lost in the move off Paddle, and it is recorded here rather
 * than quietly dropped. Paddle signed a timestamp alongside the body and Cashier
 * refused anything more than five seconds old, so a captured payload could not be
 * replayed. Lemon Squeezy signs the raw body alone — `hash_hmac('sha256', $body,
 * $secret)` in `X-Signature` — so a payload captured off the wire stays valid
 * forever and there is nothing in the request to reject it by. Replay protection
 * here is weaker than it was, the provider offers no counterpart, and the tests
 * below therefore have none to assert. That the handlers do little harm on replay
 * is a consequence rather than a defence.
 */

const WEBHOOK_SECRET = 'ls_signing_secret_for_tests';

beforeEach(function (): void {
    config([
        'lemon-squeezy.signing_secret' => WEBHOOK_SECRET,
        'plans.prices' => ['pro' => ['monthly' => 'var_pro_monthly']],
    ]);
});

test('the webhook route is registered', function (): void {
    expect(Route::has('lemon-squeezy.webhook'))->toBeTrue();
    expect(route('lemon-squeezy.webhook', absolute: false))->toBe('/lemon-squeezy/webhook');
});

/**
 * Lemon Squeezy posts without a session or a CSRF token, so the endpoint must
 * sit outside the `web` group. Were it inside, every real webhook would be
 * rejected with a 419 and no subscription would ever land.
 *
 * This cannot be asserted by posting without a token, because
 * PreventRequestForgery waves through anything it recognises as a test run —
 * a webhook route fully behind CSRF would pass a behavioural check here and
 * then reject every real call in production. So the middleware is inspected
 * instead, and inspected through the router: Route::gatherMiddleware() lists
 * the `web` group by name without expanding it and without applying the
 * route's own exclusions, so it reports the string "web" for this route and
 * would find no CSRF class either way. Router::gatherRouteMiddleware() is
 * what dispatch actually runs, groups resolved and exclusions applied.
 *
 * Matched on any middleware whose name mentions CSRF rather than on one
 * named class, because Laravel 13 renamed it to PreventRequestForgery and
 * left ValidateCsrfToken as a deprecated subclass — an assertion naming the
 * old class would pass with CSRF fully enforced.
 *
 * The control assertion at the end is not decoration. Every part of this
 * test is an absence, and an absence proves nothing unless the same query
 * finds the thing present somewhere it should be.
 */
test('the webhook route is exempt from csrf', function (): void {
    $middleware = gatheredMiddlewareFor('lemon-squeezy.webhook');

    expect(VerifyLemonSqueezyWebhookSignature::class)->toBeIn($middleware);

    expect(csrfMiddlewareIn($middleware))->toBeEmpty('The Lemon Squeezy webhook must not run behind CSRF verification.');

    expect(csrfMiddlewareIn(gatheredMiddlewareFor('home')))
        ->not->toBeEmpty('An ordinary web route should still be behind CSRF; if it is not, the assertion above proves nothing.');
});

test('an unsigned payload is rejected', function (): void {
    $this->postJson(route('lemon-squeezy.webhook'), subscriptionCreatedPayload(1))
        ->assertForbidden();

    $this->assertDatabaseCount('lemon_squeezy_subscriptions', 0);
});

/**
 * The failure mode that matters most, because it is the one an environment
 * reaches by omission rather than by attack.
 *
 * The package applies its signature middleware only when a signing secret is
 * configured, so an environment that forgets `LEMON_SQUEEZY_SIGNING_SECRET`
 * would accept every POST to this endpoint and hand Pro to anyone who found
 * the URL. VerifyLemonSqueezyWebhookSignature makes the check unconditional:
 * with no secret, nothing is accepted.
 *
 * The second call is the point of the test. Deferring to the package's
 * middleware would not be enough even if it always ran, because with an unset
 * secret it hashes the body with an empty key — and a forger who hashes with
 * an empty key matches it exactly. A signature computed that way must still
 * be refused.
 */
test('a missing signing secret rejects every call rather than accepting them', function (): void {
    config(['lemon-squeezy.signing_secret' => null]);

    $user = User::factory()->create();
    $payload = subscriptionCreatedPayload($user->id);

    $this->postJson(route('lemon-squeezy.webhook'), $payload)->assertForbidden();

    $this->call(
        'POST',
        route('lemon-squeezy.webhook'),
        server: signature($payload, secret: ''),
        content: (string) json_encode($payload),
    )->assertForbidden();

    $this->call(
        'POST',
        route('lemon-squeezy.webhook'),
        server: signature($payload),
        content: (string) json_encode($payload),
    )->assertForbidden();

    $this->assertDatabaseCount('lemon_squeezy_subscriptions', 0);
    expect($user->fresh()?->plan())->toBe(Plan::Starter);
});

/**
 * An empty string is the shape an unset variable takes when it is present in
 * a `.env` file with nothing after the `=` rather than absent from it, and it
 * has to fail exactly the way a missing key does.
 */
test('an empty signing secret rejects every call', function (): void {
    config(['lemon-squeezy.signing_secret' => '']);

    $payload = subscriptionCreatedPayload(1);

    $this->call(
        'POST',
        route('lemon-squeezy.webhook'),
        server: signature($payload, secret: ''),
        content: (string) json_encode($payload),
    )->assertForbidden();

    $this->assertDatabaseCount('lemon_squeezy_subscriptions', 0);
});

test('a payload signed with the wrong secret is rejected', function (): void {
    $payload = subscriptionCreatedPayload(1);

    $this->call(
        'POST',
        route('lemon-squeezy.webhook'),
        server: signature($payload, 'ls_someone_elses_secret'),
        content: (string) json_encode($payload),
    )->assertForbidden();

    $this->assertDatabaseCount('lemon_squeezy_subscriptions', 0);
});

/**
 * The package's own middleware type-hints a `string` signature, so a request
 * that simply omits the header would raise a TypeError and surface as a 500
 * rather than as a refusal. Ours checks the header before it compares it.
 */
test('a signed body sent without the header is rejected', function (): void {
    $payload = subscriptionCreatedPayload(1);

    $this->call(
        'POST',
        route('lemon-squeezy.webhook'),
        server: ['CONTENT_TYPE' => 'application/json'],
        content: (string) json_encode($payload),
    )->assertForbidden();
});

/**
 * The signature covers the body byte for byte, so a payload edited after it
 * was signed no longer matches — an intercepted webhook cannot have its
 * variant swapped for a more generous one on the way through.
 */
test('a body altered after signing is rejected', function (): void {
    $payload = subscriptionCreatedPayload(1);
    $signature = signature($payload);

    $payload['data']['attributes']['variant_id'] = 'var_team_monthly';

    $this->call(
        'POST',
        route('lemon-squeezy.webhook'),
        server: $signature,
        content: (string) json_encode($payload),
    )->assertForbidden();

    $this->assertDatabaseCount('lemon_squeezy_subscriptions', 0);
});

test('a signed payload is accepted and grants the plan it sells', function (): void {
    $user = User::factory()->create();

    $payload = subscriptionCreatedPayload($user->id);

    $this->call(
        'POST',
        route('lemon-squeezy.webhook'),
        server: signature($payload),
        content: (string) json_encode($payload),
    )->assertOk();

    $this->assertDatabaseHas('lemon_squeezy_subscriptions', [
        'lemon_squeezy_id' => '900001',
        'billable_id' => $user->id,
        'billable_type' => $user->getMorphClass(),
        'type' => Subscription::DEFAULT_TYPE,
        'status' => Subscription::STATUS_ACTIVE,
        'variant_id' => 'var_pro_monthly',
        'card_brand' => 'visa',
        'card_last_four' => '4242',
    ]);

    /*
     * A customer row is created alongside the subscription, which is what
     * later lets the billing page name the card on file without asking
     * anyone.
     */
    $this->assertDatabaseHas('lemon_squeezy_customers', [
        'billable_id' => $user->id,
        'lemon_squeezy_id' => '800001',
    ]);

    /*
     * The point of the whole phase: a webhook Lemon Squeezy sent is the only
     * thing that has to happen for entitlements to flip. Resolution reads the
     * subscription table on the next request, so there is no cache to
     * invalidate and nothing to log out and back in for.
     */
    expect($user->fresh()?->plan())->toBe(Plan::Pro);
});

/**
 * A signed payload naming a variant this environment does not sell is still
 * recorded — it is a real subscription and refusing to store it would lose
 * track of money already taken — but it entitles nobody. Granting a default
 * plan for an ID we cannot account for would be worse than granting nothing.
 */
test('a signed payload for an unknown variant grants nothing', function (): void {
    $user = User::factory()->create();

    $payload = subscriptionCreatedPayload($user->id, variantId: 'var_retired_experiment');

    $this->call(
        'POST',
        route('lemon-squeezy.webhook'),
        server: signature($payload),
        content: (string) json_encode($payload),
    )->assertOk();

    $this->assertDatabaseCount('lemon_squeezy_subscriptions', 1);
    expect($user->fresh()?->plan())->toBe(Plan::Starter);
});

/**
 * Custom data is what ties a Lemon Squeezy subscription back to an account,
 * and StartCheckout is the only thing that sets it. A payload arriving
 * without it names nobody, so nothing may be created from it.
 *
 * It is also acknowledged rather than refused. Lemon Squeezy redelivers
 * everything that is not 2xx, and no number of redeliveries will put custom
 * data into a payload that was sent without it — a subscription started by
 * hand in the dashboard, or through a payment link shared outside this
 * application, never had any. Refusing it buys a retry loop and nothing
 * else. See PreventLemonSqueezyWebhookRetryLoops.
 */
test('a signed payload naming no billable is acknowledged and creates nothing', function (): void {
    $payload = subscriptionCreatedPayload(1);
    unset($payload['meta']['custom_data']);

    $this->call(
        'POST',
        route('lemon-squeezy.webhook'),
        server: signature($payload),
        content: (string) json_encode($payload),
    )->assertOk();

    $this->assertDatabaseCount('lemon_squeezy_subscriptions', 0);
    $this->assertDatabaseCount('lemon_squeezy_customers', 0);
});

/**
 * A payload naming an account that is not here is the worse version of the
 * same problem, and it was the one that did damage. `findOrCreateCustomer`
 * writes the customer row before it reads the billable back, so a dangling
 * `billable_id` left an orphan row, and the null it then called a method on
 * raised an `Error` — which the package's controller does not catch, because
 * it catches `Exception`. Every redelivery left another orphan behind.
 */
test('a signed payload naming an account that does not exist leaves no orphan behind', function (): void {
    $payload = subscriptionCreatedPayload(404_404);

    $this->call(
        'POST',
        route('lemon-squeezy.webhook'),
        server: signature($payload),
        content: (string) json_encode($payload),
    )->assertOk();

    $this->assertDatabaseCount('lemon_squeezy_subscriptions', 0);
    $this->assertDatabaseCount('lemon_squeezy_customers', 0);
});

/**
 * The retry loop this endpoint used to be able to enter, and the reason the
 * middleware exists.
 *
 * `subscription_created` inserts against a unique `lemon_squeezy_id`, so a
 * redelivery raised a QueryException — not one of the two throwables the
 * package's controller special-cases, so it came back as a 500. Lemon
 * Squeezy redelivers anything that is not 2xx, into the same constraint, for
 * as long as it is willing to. The first delivery had already done the work.
 */
test('a redelivered subscription is acknowledged rather than recorded twice', function (): void {
    $user = User::factory()->create();
    $payload = subscriptionCreatedPayload($user->id);

    foreach (range(1, 3) as $ignored) {
        $this->call(
            'POST',
            route('lemon-squeezy.webhook'),
            server: signature($payload),
            content: (string) json_encode($payload),
        )->assertOk();
    }

    $this->assertDatabaseCount('lemon_squeezy_subscriptions', 1);
    $this->assertDatabaseCount('lemon_squeezy_customers', 1);
    expect($user->fresh()?->plan())->toBe(Plan::Pro);
});

/**
 * Orders insert the same way subscriptions do, against the same kind of
 * unique column, and are the other half of what the middleware guards. They
 * are this pilot's receipts, so a redelivery billing them twice on the
 * settings page would be its own bug even if it did not 500 first.
 */
test('a redelivered order is acknowledged rather than recorded twice', function (): void {
    $user = User::factory()->create();
    $payload = orderCreatedPayload($user->id);

    foreach (range(1, 3) as $ignored) {
        $this->call(
            'POST',
            route('lemon-squeezy.webhook'),
            server: signature($payload),
            content: (string) json_encode($payload),
        )->assertOk();
    }

    $this->assertDatabaseCount('lemon_squeezy_orders', 1);
});

/**
 * A second, genuinely different purchase is not a redelivery, and must not
 * be mistaken for one — the guard keys on the Lemon Squeezy ID, not on the
 * account.
 */
test('a second distinct subscription is still recorded', function (): void {
    $user = User::factory()->create();

    foreach (['900001', '900002'] as $id) {
        $payload = subscriptionCreatedPayload($user->id, subscriptionId: $id);

        $this->call(
            'POST',
            route('lemon-squeezy.webhook'),
            server: signature($payload),
            content: (string) json_encode($payload),
        )->assertOk();
    }

    $this->assertDatabaseCount('lemon_squeezy_subscriptions', 2);
});

/**
 * The signature authenticates the caller; it says nothing about what the
 * caller sent. An event this application has no handler for is acknowledged
 * rather than retried forever.
 */
test('a signed payload for an unhandled event is acknowledged', function (): void {
    $payload = ['meta' => ['event_name' => 'subscription_plan_changed'], 'data' => ['id' => '1']];

    $this->call(
        'POST',
        route('lemon-squeezy.webhook'),
        server: signature($payload),
        content: (string) json_encode($payload),
    )->assertOk();
});

/**
 * The fields the package's controller reads out of an `order_created` call.
 *
 * An order is a receipt rather than an entitlement — nothing here grants a
 * plan — but it inserts against a unique `lemon_squeezy_id` exactly as a
 * subscription does, which is what makes a redelivery of one worth pinning.
 *
 * @return array<string, mixed>
 */
function orderCreatedPayload(int|string $billableId): array
{
    return [
        'meta' => [
            'event_name' => 'order_created',
            'custom_data' => [
                'billable_id' => (string) $billableId,
                'billable_type' => (new User)->getMorphClass(),
            ],
        ],
        'data' => [
            'type' => 'orders',
            'id' => '700001',
            'attributes' => [
                'store_id' => 42,
                'customer_id' => 800001,
                'identifier' => '3f1c8a90-5d0e-4f2b-9d3a-7c6e5b4a1029',
                'order_number' => 1042,
                'currency' => 'USD',
                'subtotal' => 1900,
                'discount_total' => 0,
                'tax' => 0,
                'total' => 1900,
                'tax_name' => null,
                'status' => 'paid',
                'refunded' => false,
                'refunded_at' => null,
                'first_order_item' => [
                    'product_id' => 'prod_droneverse_pro',
                    'variant_id' => 'var_pro_monthly',
                ],
                'urls' => ['receipt' => 'https://app.lemonsqueezy.com/my-orders/3f1c8a90'],
                'created_at' => '2026-08-01T00:00:00.000000Z',
                'updated_at' => '2026-08-01T00:00:00.000000Z',
            ],
        ],
    ];
}

/**
 * The middleware a request to this route actually runs through, with the
 * `web` group expanded and the route's exclusions applied.
 *
 * @return array<int, string>
 */
function gatheredMiddlewareFor(string $routeName): array
{
    $route = Route::getRoutes()->getByName($routeName);

    expect($route)->not->toBeNull(sprintf('There is no route named [%s].', $routeName));

    return resolve(Router::class)->gatherRouteMiddleware($route);
}

/**
 * @param  array<int, string>  $middleware
 * @return array<int, string>
 */
function csrfMiddlewareIn(array $middleware): array
{
    return array_values(array_filter(
        $middleware,
        static fn (string $name): bool => str_contains(mb_strtolower($name), 'csrf')
            || str_contains(mb_strtolower($name), 'requestforgery'),
    ));
}

/**
 * Lemon Squeezy signs the raw request body with the store's signing secret
 * and sends the hash in `X-Signature`. There is no timestamp and no version
 * prefix; see this class's doc-block for what that costs.
 *
 * @param  array<string, mixed>  $payload
 * @return array<string, string>
 */
function signature(array $payload, string $secret = WEBHOOK_SECRET): array
{
    return [
        'HTTP_X_SIGNATURE' => hash_hmac('sha256', (string) json_encode($payload), $secret),
        'CONTENT_TYPE' => 'application/json',
    ];
}

/**
 * The fields LemonSqueezy\Laravel\Http\Controllers\WebhookController reads
 * out of a `subscription_created` call, in the shape it reads them from.
 *
 * `custom_data` is the object StartCheckout attaches to the checkout:
 * `billable_id`, `billable_type` and `subscription_type` are the package's
 * reserved keys, and `plan` and `variant` are ours, carried along for anyone
 * reading the payload later.
 *
 * @return array<string, mixed>
 */
function subscriptionCreatedPayload(
    int|string $billableId,
    string $variantId = 'var_pro_monthly',
    string $status = Subscription::STATUS_ACTIVE,
    string $subscriptionId = '900001',
): array {
    return [
        'meta' => [
            'event_name' => 'subscription_created',
            'custom_data' => [
                'billable_id' => (string) $billableId,
                'billable_type' => (new User)->getMorphClass(),
                'subscription_type' => Subscription::DEFAULT_TYPE,
                'plan' => 'pro',
                'variant' => 'monthly',
            ],
        ],
        'data' => [
            'type' => 'subscriptions',
            'id' => $subscriptionId,
            'attributes' => [
                'store_id' => 42,
                'customer_id' => 800001,
                'order_id' => 700001,
                'product_id' => 'prod_droneverse_pro',
                'variant_id' => $variantId,
                'product_name' => 'DroneVerse Pro',
                'variant_name' => 'Monthly',
                'user_name' => 'Test Pilot',
                'user_email' => 'pilot@example.test',
                'status' => $status,
                'status_formatted' => 'Active',
                'card_brand' => 'visa',
                'card_last_four' => '4242',
                'pause' => null,
                'cancelled' => false,
                'trial_ends_at' => null,
                'billing_anchor' => 1,
                'renews_at' => '2026-09-01T00:00:00.000000Z',
                'ends_at' => null,
                'created_at' => '2026-08-01T00:00:00.000000Z',
                'updated_at' => '2026-08-01T00:00:00.000000Z',
            ],
        ],
    ];
}
