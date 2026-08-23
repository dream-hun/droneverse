<?php

declare(strict_types=1);

use App\Enums\Plan;
use App\Enums\SubscriptionStatus;
use App\Http\Middleware\HandleInertiaRequests;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Contracts\Container\BindingResolutionException;
use Illuminate\Support\Facades\Http;

const CREEM_API = 'https://test-api.creem.io/v1';

beforeEach(function (): void {
    config([
        'plans.prices' => [
            'pro' => [
                'monthly' => 'prod_pro_monthly',
                'yearly' => 'prod_pro_yearly',
            ],
            'team' => [
                'monthly' => 'prod_team_monthly',
                'yearly' => 'prod_team_yearly',
            ],
        ],
    ]);

    /*
     * Nothing in these tests may reach Creem for real. The four routes that
     * act on a subscription — cancel, resume, change plan, open the portal —
     * fake the answer they need explicitly; anything else is a bug worth a
     * failure rather than a network round trip.
     */
    Http::preventStrayRequests();
});

test('billing requires an account', function (): void {
    $this->get(route('billing.edit'))->assertRedirect(route('login'));
});

test('a starter pilot sees a page with nothing to bill', function (): void {
    $this->actingAs(User::factory()->create())
        ->get(route('billing.edit'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('settings/billing')
            ->where('plan.value', 'starter')
            ->where('plan.source', 'none')
            ->where('subscription', null)
            ->where('orders', []));
});

/**
 * A comped account holds a plan with no subscription behind it. Telling
 * those pilots "no active subscription" would be alarming and wrong, so the
 * page is driven by `source` instead.
 */
test('a comped account is reported as an override', function (): void {
    $this->actingAs(User::factory()->onPlan(Plan::Pro)->create())
        ->get(route('billing.edit'))
        ->assertInertia(fn ($page) => $page
            ->where('plan.value', 'pro')
            ->where('plan.source', 'override')
            ->where('subscription', null));
});

test('a subscriber sees their plan and billing period', function (): void {
    $user = User::factory()->create();
    billingSubscribe($user, 'prod_pro_yearly');

    $this->actingAs($user)
        ->get(route('billing.edit'))
        ->assertInertia(fn ($page) => $page
            ->where('plan.value', 'pro')
            ->where('plan.source', 'subscription')
            ->where('subscription.planLabel', 'Pro')
            ->where('subscription.variant', 'yearly')
            ->where('subscription.valid', true)
            ->where('subscription.cancelled', false));
});

/**
 * The headline change in the move off Paddle, asserted rather than assumed.
 *
 * The old page had to ask Paddle for the next bill date, which meant it
 * rendered differently — and could fail — depending on whether the
 * environment had credentials and whether the provider was up. Creem sends the
 * next transaction date on every subscription event, so the whole page is
 * local reads. With stray requests forbidden and no credentials configured, a
 * page that still reached out would fail here rather than in production.
 */
test('the billing page reaches nobody', function (): void {
    $user = User::factory()->create();
    billingSubscribe($user, 'prod_pro_monthly', renewsAt: now()->addMonth());
    order($user, 'ord_900001');

    expect(config('creem.api_key'))->toBeEmpty();

    $this->actingAs($user)
        ->get(route('billing.edit'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('subscription.valid', true)
            ->where('orders.0.id', 'ord_900001'));

    Http::assertNothingSent();
});

test('the renewal date is read from the subscription row', function (): void {
    $user = User::factory()->create();
    billingSubscribe($user, 'prod_pro_monthly', renewsAt: new DateTimeImmutable('2026-09-01T00:00:00+00:00'));

    $this->actingAs($user)
        ->get(route('billing.edit'))
        ->assertInertia(fn ($page) => $page
            ->where('subscription.renewsAt', '2026-09-01T00:00:00+00:00')
            ->where('subscription.valid', true));
});

/**
 * A subscription scheduled to end can still carry the renewal date it had
 * before somebody cancelled it, so `renewsAt` alone would read as a promise to
 * charge again. It is sent as it stands, and `cancelled`, `onGracePeriod` and
 * `endsAt` are sent beside it — the page branches on those first and tells a
 * cancelled pilot when their access ends, never when it renews.
 *
 * `endsAt` is derived rather than stored: Creem reports a period end, a
 * cancellation time and a next-transaction date, and which of them ends the
 * subscription depends on how it is ending. See App\Models\Subscription.
 */
test('a cancelled subscription reports its ending alongside its stale renewal date', function (): void {
    $user = User::factory()->create();
    billingSubscribe(
        $user,
        'prod_pro_monthly',
        status: SubscriptionStatus::ScheduledCancel,
        periodEndsAt: new DateTimeImmutable('2026-08-27T00:00:00+00:00'),
        renewsAt: new DateTimeImmutable('2026-08-27T00:00:00+00:00'),
    );

    $this->travelTo('2026-08-10T00:00:00+00:00');

    $this->actingAs($user)
        ->get(route('billing.edit'))
        ->assertInertia(fn ($page) => $page
            ->where('subscription.cancelled', true)
            ->where('subscription.onGracePeriod', true)
            ->where('subscription.endsAt', '2026-08-27T00:00:00+00:00')
            ->where('subscription.renewsAt', '2026-08-27T00:00:00+00:00')
            ->where('subscription.valid', true));
});

/**
 * A real loss against the Lemon Squeezy integration, pinned so that nobody
 * reintroduces the columns by guessing at them.
 *
 * Creem publishes no card brand and no last four on any payload it sends —
 * not on a checkout, not on a subscription, not on an order. So this page
 * cannot name the card being charged, and it does not pretend to: the
 * "Manage billing" button beside the plan opens Creem's own portal, which is
 * the only place a pilot sees their card.
 */
test('the page says nothing about the card, because nothing is published about it', function (): void {
    $user = User::factory()->create();
    billingSubscribe($user, 'prod_pro_monthly');

    $this->actingAs($user)
        ->get(route('billing.edit'))
        ->assertInertia(fn ($page) => $page
            ->has('subscription')
            ->missing('subscription.cardBrand')
            ->missing('subscription.cardLastFour'));
});

test('receipts are listed newest first', function (): void {
    $user = User::factory()->create();

    order($user, 'ord_900001', orderedAt: '2026-05-01 10:00:00');
    order($user, 'ord_900002', amount: 19000);

    $this->actingAs($user)
        ->get(route('billing.edit'))
        ->assertInertia(fn ($page) => $page
            ->where('orders.0.id', 'ord_900002')
            ->where('orders.0.total', '$190.00')
            ->where('orders.0.refunded', false)
            ->where('orders.0.refundedTotal', null)
            ->where('orders.1.id', 'ord_900001')
            ->where('orders.1.total', '$19.00'));
});

/**
 * Creem allows partial refunds, so the flag alone would tell a pilot their
 * whole year came back when a month did. The amount travels beside it and the
 * page prints it in the badge.
 */
test('a partial refund reports what actually came back', function (): void {
    $user = User::factory()->create();
    order($user, 'ord_900001', amount: 19000)
        ->forceFill(['refunded' => true, 'refunded_amount' => 1900, 'refunded_at' => now()])
        ->save();

    $this->actingAs($user)
        ->get(route('billing.edit'))
        ->assertInertia(fn ($page) => $page
            ->where('orders.0.total', '$190.00')
            ->where('orders.0.refunded', true)
            ->where('orders.0.refundedTotal', '$19.00'));
});

/**
 * There is no receipt link on a row, and there is not meant to be. Creem
 * publishes no per-order receipt URL — invoices are behind the customer
 * portal, reached by a magic link minted per request — so the page links to
 * the portal once rather than carrying a document link it cannot fill in.
 */
test('an order carries no receipt link, because creem publishes none', function (): void {
    $user = User::factory()->create();
    order($user, 'ord_900001', status: 'failed');

    $this->actingAs($user)
        ->get(route('billing.edit'))
        ->assertInertia(fn ($page) => $page
            ->where('orders.0.status', 'failed')
            ->missing('orders.0.receiptUrl'));
});

test('one pilots receipts do not leak to another', function (): void {
    $payer = User::factory()->create();
    order($payer, 'ord_900001');

    $this->actingAs(User::factory()->create())
        ->get(route('billing.edit'))
        ->assertInertia(fn ($page) => $page->where('orders', []));
});

test('cancelling schedules the end of the paid period', function (): void {
    $user = User::factory()->create();
    $subscription = billingSubscribe($user, 'prod_pro_monthly');

    fakeSubscriptionApi($subscription, 'cancel', [
        'status' => SubscriptionStatus::ScheduledCancel->value,
        'current_period_end_date' => '2026-08-27T00:00:00.000Z',
    ]);

    $this->travelTo('2026-08-10T00:00:00+00:00');

    $this->actingAs($user)
        ->delete(route('subscription.destroy'))
        ->assertRedirect(route('billing.edit'));

    $subscription->refresh();

    expect($subscription->cancelled())->toBeTrue()
        ->and($subscription->onGracePeriod())->toBeTrue()
        ->and(lastCreemRequest()['mode'])->toBe('scheduled')
        ->and($user->fresh()?->plan())->toBe(Plan::Pro);
});

/**
 * Cancelling is a live API call, so it can fail for reasons that have
 * nothing to do with the pilot. They get a toast and a page, not a 500, and
 * the subscription is left exactly as it was.
 */
test('a failed cancellation is reported rather than thrown', function (): void {
    $user = User::factory()->create();
    $subscription = billingSubscribe($user, 'prod_pro_monthly');

    fakeCreemOutage();

    $this->actingAs($user)
        ->delete(route('subscription.destroy'))
        ->assertRedirect(route('billing.edit'));

    expect($subscription->refresh()->cancelled())->toBeFalse()
        ->and($user->fresh()?->plan())->toBe(Plan::Pro);
});

test('resuming calls off a pending cancellation', function (): void {
    $user = User::factory()->create();
    $subscription = billingSubscribe(
        $user,
        'prod_pro_monthly',
        status: SubscriptionStatus::ScheduledCancel,
        periodEndsAt: now()->addWeek(),
    );

    fakeSubscriptionApi($subscription, 'resume', [
        'status' => SubscriptionStatus::Active->value,
        'next_transaction_date' => '2026-09-01T00:00:00.000Z',
    ]);

    $this->actingAs($user)
        ->put(route('subscription.update'))
        ->assertRedirect(route('billing.edit'));

    $subscription->refresh();

    expect($subscription->endsAt())->toBeNull()
        ->and($subscription->onGracePeriod())->toBeFalse()
        ->and($subscription->status())->toBe(SubscriptionStatus::Active);
});

/**
 * The grace-period guard earns its keep twice over: it is the rule that a
 * lapsed subscription is bought again rather than resumed, and it is also what
 * keeps Creem's own refusal out of the request — its resume endpoint accepts
 * only a subscription in `scheduled_cancel` or `paused`.
 */
test('resuming a subscription that has already ended fails without calling creem', function (): void {
    $user = User::factory()->create();
    billingSubscribe($user, 'prod_pro_monthly', status: SubscriptionStatus::Expired);

    $this->actingAs($user)
        ->put(route('subscription.update'))
        ->assertRedirect(route('billing.edit'));

    Http::assertNothingSent();
});

test('a starter pilot has no subscription to cancel', function (): void {
    $this->actingAs(User::factory()->create())
        ->delete(route('subscription.destroy'))
        ->assertRedirect(route('billing.edit'));

    Http::assertNothingSent();
});

/**
 * There is no subscription identifier anywhere in the request, so there is
 * nothing for one pilot to substitute for another's.
 */
test('cancelling only ever reaches your own subscription', function (): void {
    $subscriber = User::factory()->create();
    $theirs = billingSubscribe($subscriber, 'prod_pro_monthly');

    $this->actingAs(User::factory()->create())
        ->delete(route('subscription.destroy'));

    expect($theirs->refresh()->cancelled())->toBeFalse();
    Http::assertNothingSent();
});

test('a subscriber is offered every plan and period they could move to', function (): void {
    $user = User::factory()->create();
    billingSubscribe($user, 'prod_pro_monthly');

    $this->actingAs($user)
        ->get(route('billing.edit'))
        ->assertInertia(fn ($page) => $page
            ->where('switchable.0.value', 'pro')
            ->where('switchable.0.variants.0.value', 'monthly')
            ->where('switchable.0.variants.0.formatted', '$19')
            ->where('switchable.0.variants.0.isCurrent', true)
            ->where('switchable.0.variants.1.value', 'yearly')
            ->where('switchable.0.variants.1.isCurrent', false)
            ->where('switchable.1.value', 'team')
            ->where('switchable.1.variants.0.formatted', '$59')
            // Starter is free, so it is not somewhere a subscription can be
            // moved to.
            ->count('switchable', 2));
});

/**
 * A period with no configured price is left out rather than offered and
 * refused: this is a short list in a dialog, and there is nothing to argue
 * for by advertising something that cannot be selected.
 */
test('a period with no configured price is not offered as a destination', function (): void {
    config(['plans.prices.team' => ['monthly' => 'prod_team_monthly']]);

    $user = User::factory()->create();
    billingSubscribe($user, 'prod_pro_monthly');

    $this->actingAs($user)
        ->get(route('billing.edit'))
        ->assertInertia(fn ($page) => $page
            ->where('switchable.1.value', 'team')
            ->count('switchable.1.variants', 1)
            ->where('switchable.1.variants.0.value', 'monthly'));
});

/**
 * A comped account has no billing to change, and a pilot with no
 * subscription at all buys one from the pricing page. Both get an empty
 * list, which is how the page knows to offer no change-plan control.
 */
test('an account with no subscription has nothing to switch', function (): void {
    $this->actingAs(User::factory()->onPlan(Plan::Pro)->create())
        ->get(route('billing.edit'))
        ->assertInertia(fn ($page) => $page->where('switchable', []));

    $this->actingAs(User::factory()->create())
        ->get(route('billing.edit'))
        ->assertInertia(fn ($page) => $page->where('switchable', []));
});

test('a subscription winding down offers nothing to switch to', function (): void {
    $user = User::factory()->create();
    billingSubscribe(
        $user,
        'prod_pro_monthly',
        status: SubscriptionStatus::ScheduledCancel,
        periodEndsAt: now()->addWeek(),
    );

    $this->actingAs($user)
        ->get(route('billing.edit'))
        ->assertInertia(fn ($page) => $page
            ->where('switchable', [])
            // Still theirs until it runs out, and still resumable.
            ->where('subscription.onGracePeriod', true));
});

test('switching tier reprices the one subscription', function (): void {
    $user = User::factory()->create();
    $subscription = billingSubscribe($user, 'prod_pro_monthly');

    fakeSubscriptionApi($subscription, 'upgrade', ['product' => 'prod_team_yearly']);

    $this->actingAs($user)
        ->put(route('subscription.swap'), ['plan' => 'team', 'variant' => 'yearly'])
        ->assertRedirect(route('billing.edit'));

    $sent = lastCreemRequest();

    expect($sent['product_id'])->toBe('prod_team_yearly')
        ->and($sent['update_behavior'])->toBe('proration-charge-immediately')
        ->and($subscription->refresh()->product_id)->toBe('prod_team_yearly')
        ->and($user->fresh()?->plan())->toBe(Plan::Team);
    /*
     * Prorated, and settled now. Creem's alternative is `proration-none`,
     * which would hand an upgrading pilot the rest of the month for free and
     * take a downgrading one's unused period away without refunding it — the
     * same money either way, and only one party notices.
     */

});

test('switching billing period keeps the plan', function (): void {
    $user = User::factory()->create();
    $subscription = billingSubscribe($user, 'prod_pro_monthly');

    fakeSubscriptionApi($subscription, 'upgrade', ['product' => 'prod_pro_yearly']);

    $this->actingAs($user)
        ->put(route('subscription.swap'), ['plan' => 'pro', 'variant' => 'yearly'])
        ->assertRedirect(route('billing.edit'));

    expect($subscription->refresh()->product_id)->toBe('prod_pro_yearly')
        ->and($user->fresh()?->plan())->toBe(Plan::Pro);
});

/**
 * The card has already been charged by the time Creem answers, so a response
 * this application cannot read in full is not a change that did not happen.
 * The entitlement is written from what was asked for, and the dates are left
 * to the webhook that follows.
 */
test('a switch survives an answer that cannot be read in full', function (): void {
    $user = User::factory()->create();
    $subscription = billingSubscribe($user, 'prod_pro_monthly');

    config(['creem.api_key' => 'creem_test_key']);

    Http::fake([
        CREEM_API.'/subscriptions/'.$subscription->creem_id.'/upgrade' => Http::response(['ok' => true]),
    ]);

    $this->actingAs($user)
        ->put(route('subscription.swap'), ['plan' => 'team', 'variant' => 'yearly'])
        ->assertRedirect(route('billing.edit'));

    expect($subscription->refresh()->product_id)->toBe('prod_team_yearly')
        ->and($user->fresh()?->plan())->toBe(Plan::Team);
});

/**
 * Submitting the dialog without touching it. Nothing to do, and nothing to
 * ask Creem about.
 */
test('switching to the plan you are already on reaches nobody', function (): void {
    $user = User::factory()->create();
    billingSubscribe($user, 'prod_pro_monthly');

    $this->actingAs($user)
        ->put(route('subscription.swap'), ['plan' => 'pro', 'variant' => 'monthly'])
        ->assertRedirect(route('billing.edit'));

    Http::assertNothingSent();
});

test('switching refuses the free tier and an unsold period', function (): void {
    $user = User::factory()->create();
    $subscription = billingSubscribe($user, 'prod_pro_monthly');

    foreach ([
        ['plan' => 'starter', 'variant' => 'monthly'],
        ['plan' => 'pro', 'variant' => 'weekly'],
    ] as $attempt) {
        $this->actingAs($user)
            ->put(route('subscription.swap'), $attempt)
            ->assertRedirect(route('billing.edit'));
    }

    Http::assertNothingSent();
    expect($subscription->refresh()->product_id)->toBe('prod_pro_monthly');
});

test('switching refuses a plan that does not exist', function (): void {
    $user = User::factory()->create();
    billingSubscribe($user, 'prod_pro_monthly');

    $this->actingAs($user)
        ->put(route('subscription.swap'), ['plan' => 'platinum', 'variant' => 'monthly'])
        ->assertSessionHasErrors('plan');

    Http::assertNothingSent();
});

/**
 * A canceled subscription is still valid through its grace period, but
 * repricing something scheduled to end takes money for a plan the pilot has
 * said they do not want. Resuming is one button away on the same page.
 */
test('a subscription winding down is resumed before it is repriced', function (): void {
    $user = User::factory()->create();
    $subscription = billingSubscribe(
        $user,
        'prod_pro_monthly',
        status: SubscriptionStatus::ScheduledCancel,
        periodEndsAt: now()->addWeek(),
    );

    $this->actingAs($user)
        ->put(route('subscription.swap'), ['plan' => 'team', 'variant' => 'monthly'])
        ->assertRedirect(route('billing.edit'));

    Http::assertNothingSent();
    expect($subscription->refresh()->product_id)->toBe('prod_pro_monthly');
});

/**
 * Repricing is a live API call, so it fails for reasons that have nothing to
 * do with the pilot. They get a toast and a page, not a 500, and the
 * subscription is left exactly as it was.
 */
test('a failed switch is reported rather than thrown', function (): void {
    $user = User::factory()->create();
    $subscription = billingSubscribe($user, 'prod_pro_monthly');

    fakeCreemOutage();

    $this->actingAs($user)
        ->put(route('subscription.swap'), ['plan' => 'team', 'variant' => 'monthly'])
        ->assertRedirect(route('billing.edit'));

    expect($subscription->refresh()->product_id)->toBe('prod_pro_monthly')
        ->and($user->fresh()?->plan())->toBe(Plan::Pro);
});

test('a starter pilot has no subscription to switch', function (): void {
    $this->actingAs(User::factory()->create())
        ->put(route('subscription.swap'), ['plan' => 'pro', 'variant' => 'monthly'])
        ->assertRedirect(route('billing.edit'));

    Http::assertNothingSent();
});

/**
 * There is no subscription identifier in the request, so there is nothing
 * for one pilot to substitute for another's.
 */
test('switching only ever reaches your own subscription', function (): void {
    $subscriber = User::factory()->create();
    $theirs = billingSubscribe($subscriber, 'prod_pro_monthly');

    $this->actingAs(User::factory()->create())
        ->put(route('subscription.swap'), ['plan' => 'team', 'variant' => 'monthly']);

    expect($theirs->refresh()->product_id)->toBe('prod_pro_monthly');
    Http::assertNothingSent();
});

/**
 * The portal covers the card, the invoices and Creem's own support, so it is a
 * link out rather than a screen we render — card details should never touch a
 * page of ours.
 *
 * Minted against the customer rather than the subscription, because the two
 * outlast each other differently: somebody whose subscription ended last month
 * still has invoices to download.
 */
test('the billing portal is creems own, minted per request', function (): void {
    $user = User::factory()->create();
    billingSubscribe($user, 'prod_pro_monthly');

    fakePortalApi('https://creem.io/my-orders/login/abc123');

    $this->actingAs($user)
        ->get(route('billing-portal.edit'))
        ->assertRedirect('https://creem.io/my-orders/login/abc123');

    expect(lastCreemRequest()['customer_id'])->toBe(creemCustomerId($user));
});

/**
 * The billing page reaches this route with an Inertia <Link>, which is an
 * XHR. A plain 302 is followed by the browser and answered with Creem's HTML,
 * which carries no X-Inertia header — Inertia rejects that as an invalid
 * response rather than navigating, so the subscriber can never reach the page.
 * The 409 below is the only answer it acts on.
 */
test(/**
 * @throws BindingResolutionException
 */ /**
 * @throws BindingResolutionException
 */ 'the billing portal is reachable from an inertia visit', function (): void {
    $user = User::factory()->create();
    billingSubscribe($user, 'prod_pro_monthly');

    fakePortalApi('https://creem.io/my-orders/login/abc123');

    /*
     * Resolved through the app's own middleware rather than hardcoded: a
     * version the middleware disagrees with is answered with its own 409
     * pointing back at this page, which would pass a laxer assertion for
     * entirely the wrong reason.
     */
    $version = resolve(HandleInertiaRequests::class)->version($this->app->make('request'));

    $this->actingAs($user)
        ->get(route('billing-portal.edit'), [
            'X-Inertia' => 'true',
            'X-Inertia-Version' => (string) $version,
        ])
        ->assertStatus(409)
        ->assertHeader('X-Inertia-Location', 'https://creem.io/my-orders/login/abc123');
});

/**
 * An account Creem has never named a customer for has no portal to open, and
 * the customer row is written by the webhook rather than by checkout — so this
 * is also what a buyer sees in the seconds between paying and being recorded.
 */
test('an account creem has never seen has no portal to open', function (): void {
    $this->actingAs(User::factory()->create())
        ->get(route('billing-portal.edit'))
        ->assertRedirect(route('billing.edit'));

    Http::assertNothingSent();
});

/**
 * The body of the last call made to Creem, decoded.
 *
 * Asserted on field by field rather than matched inside a closure, so a
 * mismatch reports which one was wrong.
 *
 * @return array<string, mixed>
 */
function lastCreemRequest(): array
{
    $recorded = Http::recorded();

    expect($recorded)->not->toBeEmpty('Expected a call to Creem.');

    /** @var array<string, mixed> $data */
    $data = $recorded->last()[0]->data();

    return $data;
}

/**
 * Answer one of the subscription endpoints with the subscription as it now
 * stands, in the shape Creem's own webhooks use.
 *
 * `id`, `product`, `customer` and `status` are always present because
 * App\Actions\SyncCreemSubscription refuses a payload missing any of them —
 * and refusing is the right behavior, so a fake that omitted one would be
 * testing the fallback rather than the path.
 *
 * @param  array<string, mixed>  $attributes
 */
function fakeSubscriptionApi(Subscription $subscription, string $action, array $attributes = []): void
{
    config(['creem.api_key' => 'creem_test_key']);

    Http::fake([
        CREEM_API.'/subscriptions/'.$subscription->creem_id.'/'.$action => Http::response([
            'id' => $subscription->creem_id,
            'object' => 'subscription',
            'product' => $subscription->product_id,
            'customer' => $subscription->customer_id,
            'status' => $subscription->status,
            ...$attributes,
        ]),
    ]);
}

function fakePortalApi(string $link): void
{
    config(['creem.api_key' => 'creem_test_key']);

    Http::fake([
        CREEM_API.'/customers/billing' => Http::response(['customer_portal_link' => $link]),
    ]);
}

/**
 * Every Creem call this application makes is a POST to a path under the same
 * host, so one outage fake covers cancelling, resuming, switching and the
 * portal alike.
 */
function fakeCreemOutage(): void
{
    config(['creem.api_key' => 'creem_test_key']);

    Http::fake([
        CREEM_API.'/*' => Http::response(['error' => 'Something went wrong.'], 500),
    ]);
}

/**
 * Give the user a Creem subscription, alongside the one customer row a real
 * account has.
 */
function billingSubscribe(
    User $user,
    string $productId,
    SubscriptionStatus $status = SubscriptionStatus::Active,
    ?DateTimeInterface $periodEndsAt = null,
    ?DateTimeInterface $renewsAt = null,
): Subscription {
    $customer = customerFor($user);

    return Subscription::factory()
        ->billable($user)
        ->selling($productId)
        ->create([
            'customer_id' => $customer->creem_id,
            'status' => $status->value,
            'renews_at' => $renewsAt,
            ...($periodEndsAt instanceof DateTimeInterface ? ['current_period_end_at' => $periodEndsAt] : []),
        ]);
}

/**
 * Give the user a paid order, the way `checkout.completed` would have.
 */
function order(
    User $user,
    string $creemId,
    int $amount = 1900,
    string $orderedAt = '2026-06-01 10:00:00',
    string $status = 'paid',
): Order {
    customerFor($user);

    return Order::factory()->billable($user)->create([
        'creem_id' => $creemId,
        'amount' => $amount,
        'currency' => 'USD',
        'status' => $status,
        'ordered_at' => $orderedAt,
    ]);
}

function customerFor(User $user): Customer
{
    return Customer::query()->firstOrCreate(
        ['billable_id' => $user->id, 'billable_type' => $user->getMorphClass()],
        ['creem_id' => 'cust_'.fake()->unique()->bothify('??##??##'), 'email' => $user->email],
    );
}

function creemCustomerId(User $user): string
{
    return (string) customerFor($user)->creem_id;
}
