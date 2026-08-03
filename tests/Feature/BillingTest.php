<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\Plan;
use App\Http\Middleware\HandleInertiaRequests;
use App\Models\User;
use DateTimeImmutable;
use DateTimeInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use LemonSqueezy\Laravel\Customer;
use LemonSqueezy\Laravel\LemonSqueezy;
use LemonSqueezy\Laravel\Order;
use LemonSqueezy\Laravel\Subscription;
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
                    'monthly' => 'var_pro_monthly',
                    'yearly' => 'var_pro_yearly',
                ],
                'team' => [
                    'monthly' => 'var_team_monthly',
                    'yearly' => 'var_team_yearly',
                ],
            ],
            /*
             * Only changing an existing subscription needs these, and only when
             * the change crosses tiers: Lemon Squeezy's update endpoint takes a
             * product and a variant together and refuses a pairing that does not
             * match.
             */
            'plans.products' => [
                'pro' => 'prod_pro',
                'team' => 'prod_team',
            ],
        ]);

        /*
         * Nothing in these tests may reach Lemon Squeezy for real. The three
         * routes that act on a subscription — cancel, resume, change card — fake
         * the answer they need explicitly; anything else is a bug worth a
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
                ->where('orders', []));
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
        $this->subscribe($user, 'var_pro_yearly');

        $this->actingAs($user)
            ->get(route('billing.edit'))
            ->assertInertia(fn ($page) => $page
                ->where('plan.value', 'pro')
                ->where('plan.source', 'subscription')
                ->where('subscription.planLabel', 'Pro')
                ->where('subscription.variant', 'yearly')
                ->where('subscription.valid', true)
                ->where('subscription.cancelled', false));
    }

    /**
     * The headline change in the move off Paddle, asserted rather than assumed.
     *
     * The old page had to ask Paddle for the next bill date, which meant it
     * rendered differently — and could fail — depending on whether the
     * environment had credentials and whether the provider was up. Lemon Squeezy
     * mirrors `renews_at` onto the subscription row, so the whole page is local
     * reads. With stray requests forbidden and no credentials configured, a page
     * that still reached out would fail here rather than in production.
     */
    public function test_the_billing_page_reaches_nobody(): void
    {
        $user = User::factory()->create();
        $this->subscribe($user, 'var_pro_monthly', renewsAt: now()->addMonth());
        $this->order($user, '900001');

        $this->assertEmpty(config('lemon-squeezy.api_key'));
        $this->assertEmpty(config('lemon-squeezy.store'));

        $this->actingAs($user)
            ->get(route('billing.edit'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('subscription.valid', true)
                ->where('orders.0.id', '900001'));

        Http::assertNothingSent();
    }

    public function test_the_renewal_date_is_read_from_the_subscription_row(): void
    {
        $user = User::factory()->create();
        $this->subscribe($user, 'var_pro_monthly', renewsAt: new DateTimeImmutable('2026-09-01T00:00:00+00:00'));

        $this->actingAs($user)
            ->get(route('billing.edit'))
            ->assertInertia(fn ($page) => $page
                ->where('subscription.renewsAt', '2026-09-01T00:00:00+00:00')
                ->where('subscription.valid', true));
    }

    /**
     * Lemon Squeezy leaves the last renewal date on a cancelled subscription
     * rather than clearing it, so `renewsAt` alone would read as a promise to
     * charge again. It is sent as it stands, and `cancelled`, `onGracePeriod`
     * and `endsAt` are sent beside it — the page branches on those first and
     * tells a cancelled pilot when their access ends, never when it renews.
     */
    public function test_a_cancelled_subscription_reports_its_ending_alongside_its_stale_renewal_date(): void
    {
        $user = User::factory()->create();
        $this->subscribe(
            $user,
            'var_pro_monthly',
            status: Subscription::STATUS_CANCELLED,
            endsAt: new DateTimeImmutable('2026-08-27T00:00:00+00:00'),
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
    }

    /**
     * The card on file is stored locally by the webhook, so the page can name it
     * without asking anyone. A trial that has never charged has no card, and the
     * empty strings Lemon Squeezy sends for that case are normalised to null so
     * the page has one branch rather than two.
     */
    public function test_the_card_on_file_is_named_from_local_columns_and_absent_when_there_is_none(): void
    {
        $withCard = User::factory()->create();
        $this->subscribe($withCard, 'var_pro_monthly', cardBrand: 'visa', cardLastFour: '4242');

        $this->actingAs($withCard)
            ->get(route('billing.edit'))
            ->assertInertia(fn ($page) => $page
                ->where('subscription.cardBrand', 'visa')
                ->where('subscription.cardLastFour', '4242'));

        $onTrial = User::factory()->create();
        $this->subscribe($onTrial, 'var_pro_monthly', cardBrand: '', cardLastFour: '');

        $this->actingAs($onTrial)
            ->get(route('billing.edit'))
            ->assertInertia(fn ($page) => $page
                ->where('subscription.cardBrand', null)
                ->where('subscription.cardLastFour', null));
    }

    public function test_receipts_are_listed_newest_first(): void
    {
        $user = User::factory()->create();

        $this->order($user, '900001', total: 1900, orderNumber: 11, orderedAt: '2026-05-01 10:00:00');
        $this->order($user, '900002', total: 19000, orderNumber: 12, orderedAt: '2026-06-01 10:00:00');

        $this->actingAs($user)
            ->get(route('billing.edit'))
            ->assertInertia(fn ($page) => $page
                ->where('orders.0.id', '900002')
                ->where('orders.0.orderNumber', 12)
                ->where('orders.0.total', '$190.00')
                ->where('orders.0.receiptUrl', 'https://app.lemonsqueezy.com/my-orders/900002')
                ->where('orders.0.refunded', false)
                ->where('orders.1.id', '900001')
                ->where('orders.1.total', '$19.00'));
    }

    /**
     * An order that never reached `paid` has no receipt to link to, and the
     * empty string Lemon Squeezy leaves behind for it is normalised to null so
     * the page can render nothing rather than a link that would 404.
     */
    public function test_an_order_with_no_receipt_reports_none(): void
    {
        $user = User::factory()->create();
        $this->order($user, '900001', status: Order::STATUS_FAILED, receiptUrl: '');

        $this->actingAs($user)
            ->get(route('billing.edit'))
            ->assertInertia(fn ($page) => $page
                ->where('orders.0.status', 'failed')
                ->where('orders.0.receiptUrl', null));
    }

    public function test_one_pilots_receipts_do_not_leak_to_another(): void
    {
        $payer = User::factory()->create();
        $this->order($payer, '900001');

        $this->actingAs(User::factory()->create())
            ->get(route('billing.edit'))
            ->assertInertia(fn ($page) => $page->where('orders', []));
    }

    public function test_cancelling_schedules_the_end_of_the_paid_period(): void
    {
        $user = User::factory()->create();
        $subscription = $this->subscribe($user, 'var_pro_monthly');

        $this->fakeSubscriptionApi($subscription, [
            'status' => Subscription::STATUS_CANCELLED,
            'ends_at' => '2026-08-27T00:00:00.000000Z',
        ]);

        $this->travelTo('2026-08-10T00:00:00+00:00');

        $this->actingAs($user)
            ->delete(route('subscription.destroy'))
            ->assertRedirect(route('billing.edit'));

        $subscription->refresh();

        $this->assertTrue($subscription->cancelled());
        $this->assertTrue($subscription->onGracePeriod());
        /*
         * The whole point of cancelling at period end: they bought the month,
         * so they keep the catalogue until it runs out.
         */
        $this->assertSame(Plan::Pro, $user->fresh()?->plan());
    }

    /**
     * Cancelling is a live API call, so it can fail for reasons that have
     * nothing to do with the pilot. They get a toast and a page, not a 500, and
     * the subscription is left exactly as it was.
     */
    public function test_a_failed_cancellation_is_reported_rather_than_thrown(): void
    {
        $user = User::factory()->create();
        $subscription = $this->subscribe($user, 'var_pro_monthly');

        config(['lemon-squeezy.api_key' => 'test-api-key']);

        Http::fake([
            LemonSqueezy::API.'/subscriptions/*' => Http::response([
                'errors' => [['detail' => 'Something went wrong.', 'status' => '500']],
            ], 500),
        ]);

        $this->actingAs($user)
            ->delete(route('subscription.destroy'))
            ->assertRedirect(route('billing.edit'));

        $this->assertFalse($subscription->refresh()->cancelled());
        $this->assertSame(Plan::Pro, $user->fresh()?->plan());
    }

    public function test_resuming_calls_off_a_pending_cancellation(): void
    {
        $user = User::factory()->create();
        $subscription = $this->subscribe(
            $user,
            'var_pro_monthly',
            status: Subscription::STATUS_CANCELLED,
            endsAt: now()->addWeek(),
        );

        $this->fakeSubscriptionApi($subscription, [
            'status' => Subscription::STATUS_ACTIVE,
            'renews_at' => '2026-09-01T00:00:00.000000Z',
        ]);

        $this->actingAs($user)
            ->put(route('subscription.update'))
            ->assertRedirect(route('billing.edit'));

        $subscription->refresh();

        $this->assertNull($subscription->ends_at);
        $this->assertFalse($subscription->onGracePeriod());
        $this->assertTrue($subscription->active());
    }

    /**
     * The grace-period guard earns its keep twice over: it is the rule that a
     * lapsed subscription is bought again rather than resumed, and it is also
     * what keeps resume()'s LogicException on an expired subscription out of the
     * request.
     */
    public function test_resuming_a_subscription_that_has_already_ended_fails_without_calling_lemon_squeezy(): void
    {
        $user = User::factory()->create();
        $this->subscribe($user, 'var_pro_monthly', status: Subscription::STATUS_EXPIRED);

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
        $theirs = $this->subscribe($subscriber, 'var_pro_monthly');

        $this->actingAs(User::factory()->create())
            ->delete(route('subscription.destroy'));

        $this->assertFalse($theirs->refresh()->cancelled());
        Http::assertNothingSent();
    }

    public function test_a_subscriber_is_offered_every_plan_and_period_they_could_move_to(): void
    {
        $user = User::factory()->create();
        $this->subscribe($user, 'var_pro_monthly');

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
                // Enterprise is negotiated and Starter is free, so neither is
                // somewhere a subscription can be moved to.
                ->count('switchable', 2));
    }

    /**
     * A period with no configured price is left out rather than offered and
     * refused: this is a short list in a dialog, and there is nothing to argue
     * for by advertising something that cannot be selected.
     */
    public function test_a_period_with_no_configured_price_is_not_offered_as_a_destination(): void
    {
        config(['plans.prices.team' => ['monthly' => 'var_team_monthly']]);

        $user = User::factory()->create();
        $this->subscribe($user, 'var_pro_monthly');

        $this->actingAs($user)
            ->get(route('billing.edit'))
            ->assertInertia(fn ($page) => $page
                ->where('switchable.1.value', 'team')
                ->count('switchable.1.variants', 1)
                ->where('switchable.1.variants.0.value', 'monthly'));
    }

    /**
     * A comped account has no billing to change, and a pilot with no
     * subscription at all buys one from the pricing page. Both get an empty
     * list, which is how the page knows to offer no change-plan control.
     */
    public function test_an_account_with_no_subscription_has_nothing_to_switch(): void
    {
        $this->actingAs(User::factory()->onPlan(Plan::Pro)->create())
            ->get(route('billing.edit'))
            ->assertInertia(fn ($page) => $page->where('switchable', []));

        $this->actingAs(User::factory()->create())
            ->get(route('billing.edit'))
            ->assertInertia(fn ($page) => $page->where('switchable', []));
    }

    public function test_a_subscription_winding_down_offers_nothing_to_switch_to(): void
    {
        $user = User::factory()->create();
        $this->subscribe(
            $user,
            'var_pro_monthly',
            status: Subscription::STATUS_CANCELLED,
            endsAt: now()->addWeek(),
        );

        $this->actingAs($user)
            ->get(route('billing.edit'))
            ->assertInertia(fn ($page) => $page
                ->where('switchable', [])
                // Still theirs until it runs out, and still resumable.
                ->where('subscription.onGracePeriod', true));
    }

    public function test_switching_tier_reprices_the_one_subscription(): void
    {
        $user = User::factory()->create();
        $subscription = $this->subscribe($user, 'var_pro_monthly');

        $this->fakeSubscriptionApi($subscription, [
            'product_id' => 'prod_team',
            'variant_id' => 'var_team_yearly',
        ]);

        $this->actingAs($user)
            ->put(route('subscription.swap'), ['plan' => 'team', 'variant' => 'yearly'])
            ->assertRedirect(route('billing.edit'));

        $attributes = $this->lastSubscriptionRequest()['data']['attributes'];

        $this->assertSame('prod_team', $attributes['product_id']);
        $this->assertSame('var_team_yearly', $attributes['variant_id']);
        /*
         * Prorated, so the unused remainder of the month already paid for is
         * credited against the year being moved to.
         */
        $this->assertFalse($attributes['disable_prorations']);
        /*
         * And not invoiced on the spot. The difference lands on the next
         * renewal, which is what the button promised.
         */
        $this->assertArrayNotHasKey('invoice_immediately', $attributes);

        $this->assertSame('var_team_yearly', $subscription->refresh()->variant_id);
        $this->assertSame(Plan::Team, $user->fresh()?->plan());
    }

    public function test_switching_billing_period_keeps_the_plan(): void
    {
        $user = User::factory()->create();
        $subscription = $this->subscribe($user, 'var_pro_monthly');

        $this->fakeSubscriptionApi($subscription, ['variant_id' => 'var_pro_yearly']);

        $this->actingAs($user)
            ->put(route('subscription.swap'), ['plan' => 'pro', 'variant' => 'yearly'])
            ->assertRedirect(route('billing.edit'));

        $this->assertSame('var_pro_yearly', $subscription->refresh()->variant_id);
        $this->assertSame(Plan::Pro, $user->fresh()?->plan());
    }

    /**
     * Product IDs exist for one operation and are the only new configuration
     * plan switching needs, so an environment that has never set them must still
     * do the half of switching that stays inside one product. Crossing tiers
     * genuinely needs them, and says so by refusing rather than by sending one
     * plan's product with another's variant.
     */
    public function test_a_period_switch_needs_no_configured_product_but_a_tier_switch_does(): void
    {
        config(['plans.products' => []]);

        $user = User::factory()->create();
        $subscription = $this->subscribe($user, 'var_pro_monthly');

        $this->fakeSubscriptionApi($subscription, ['variant_id' => 'var_pro_yearly']);

        $this->actingAs($user)
            ->put(route('subscription.swap'), ['plan' => 'pro', 'variant' => 'yearly'])
            ->assertRedirect(route('billing.edit'));

        $this->assertSame('prod_pro', $this->lastSubscriptionRequest()['data']['attributes']['product_id']);
        $this->assertSame('var_pro_yearly', $subscription->refresh()->variant_id);

        Http::fake();

        $this->actingAs($user)
            ->put(route('subscription.swap'), ['plan' => 'team', 'variant' => 'monthly'])
            ->assertRedirect(route('billing.edit'));

        Http::assertNothingSent();
        $this->assertSame('var_pro_yearly', $subscription->refresh()->variant_id);
    }

    /**
     * Submitting the dialog without touching it. Nothing to do, and nothing to
     * ask Lemon Squeezy about.
     */
    public function test_switching_to_the_plan_you_are_already_on_reaches_nobody(): void
    {
        $user = User::factory()->create();
        $this->subscribe($user, 'var_pro_monthly');

        $this->actingAs($user)
            ->put(route('subscription.swap'), ['plan' => 'pro', 'variant' => 'monthly'])
            ->assertRedirect(route('billing.edit'));

        Http::assertNothingSent();
    }

    public function test_switching_refuses_a_sales_led_tier_and_an_unsold_period(): void
    {
        $user = User::factory()->create();
        $subscription = $this->subscribe($user, 'var_pro_monthly');

        foreach ([
            ['plan' => 'enterprise', 'variant' => 'monthly'],
            ['plan' => 'starter', 'variant' => 'monthly'],
            ['plan' => 'pro', 'variant' => 'weekly'],
        ] as $attempt) {
            $this->actingAs($user)
                ->put(route('subscription.swap'), $attempt)
                ->assertRedirect(route('billing.edit'));
        }

        Http::assertNothingSent();
        $this->assertSame('var_pro_monthly', $subscription->refresh()->variant_id);
    }

    public function test_switching_refuses_a_plan_that_does_not_exist(): void
    {
        $user = User::factory()->create();
        $this->subscribe($user, 'var_pro_monthly');

        $this->actingAs($user)
            ->put(route('subscription.swap'), ['plan' => 'platinum', 'variant' => 'monthly'])
            ->assertSessionHasErrors('plan');

        Http::assertNothingSent();
    }

    /**
     * A cancelled subscription is still valid through its grace period, but
     * repricing something scheduled to end takes money for a plan the pilot has
     * said they do not want. Resuming is one button away on the same page.
     */
    public function test_a_subscription_winding_down_is_resumed_before_it_is_repriced(): void
    {
        $user = User::factory()->create();
        $subscription = $this->subscribe(
            $user,
            'var_pro_monthly',
            status: Subscription::STATUS_CANCELLED,
            endsAt: now()->addWeek(),
        );

        $this->actingAs($user)
            ->put(route('subscription.swap'), ['plan' => 'team', 'variant' => 'monthly'])
            ->assertRedirect(route('billing.edit'));

        Http::assertNothingSent();
        $this->assertSame('var_pro_monthly', $subscription->refresh()->variant_id);
    }

    /**
     * Repricing is a live API call, so it fails for reasons that have nothing to
     * do with the pilot. They get a toast and a page, not a 500, and the
     * subscription is left exactly as it was.
     */
    public function test_a_failed_switch_is_reported_rather_than_thrown(): void
    {
        $user = User::factory()->create();
        $subscription = $this->subscribe($user, 'var_pro_monthly');

        config(['lemon-squeezy.api_key' => 'test-api-key']);

        Http::fake([
            LemonSqueezy::API.'/subscriptions/*' => Http::response([
                'errors' => [['detail' => 'Something went wrong.', 'status' => '500']],
            ], 500),
        ]);

        $this->actingAs($user)
            ->put(route('subscription.swap'), ['plan' => 'team', 'variant' => 'monthly'])
            ->assertRedirect(route('billing.edit'));

        $this->assertSame('var_pro_monthly', $subscription->refresh()->variant_id);
        $this->assertSame(Plan::Pro, $user->fresh()?->plan());
    }

    public function test_a_starter_pilot_has_no_subscription_to_switch(): void
    {
        $this->actingAs(User::factory()->create())
            ->put(route('subscription.swap'), ['plan' => 'pro', 'variant' => 'monthly'])
            ->assertRedirect(route('billing.edit'));

        Http::assertNothingSent();
    }

    /**
     * There is no subscription identifier in the request, so there is nothing
     * for one pilot to substitute for another's.
     */
    public function test_switching_only_ever_reaches_your_own_subscription(): void
    {
        $subscriber = User::factory()->create();
        $theirs = $this->subscribe($subscriber, 'var_pro_monthly');

        $this->actingAs(User::factory()->create())
            ->put(route('subscription.swap'), ['plan' => 'team', 'variant' => 'monthly']);

        $this->assertSame('var_pro_monthly', $theirs->refresh()->variant_id);
        Http::assertNothingSent();
    }

    public function test_the_payment_method_page_is_lemon_squeezys_own(): void
    {
        $user = User::factory()->create();
        $subscription = $this->subscribe($user, 'var_pro_monthly');

        $this->fakeSubscriptionApi($subscription, [
            'urls' => ['update_payment_method' => 'https://droneverse.lemonsqueezy.com/update'],
        ]);

        $this->actingAs($user)
            ->get(route('payment-method.edit'))
            ->assertRedirect('https://droneverse.lemonsqueezy.com/update');
    }

    /**
     * The billing page reaches this route with an Inertia <Link>, which is an
     * XHR. A plain 302 is followed by the browser and answered with Lemon
     * Squeezy's HTML, which carries no X-Inertia header — Inertia rejects that as
     * an invalid response rather than navigating, so the subscriber can never
     * reach the page. The 409 below is the only answer it acts on.
     */
    public function test_the_payment_method_page_is_reachable_from_an_inertia_visit(): void
    {
        $user = User::factory()->create();
        $subscription = $this->subscribe($user, 'var_pro_monthly');

        $this->fakeSubscriptionApi($subscription, [
            'urls' => ['update_payment_method' => 'https://droneverse.lemonsqueezy.com/update'],
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
            ->assertHeader('X-Inertia-Location', 'https://droneverse.lemonsqueezy.com/update');
    }

    public function test_a_starter_pilot_has_no_payment_method_to_change(): void
    {
        $this->actingAs(User::factory()->create())
            ->get(route('payment-method.edit'))
            ->assertRedirect(route('billing.edit'));

        Http::assertNothingSent();
    }

    /**
     * The body of the last call made to Lemon Squeezy, decoded.
     *
     * Asserted on field by field rather than matched inside a closure, so a
     * mismatch reports which one was wrong.
     *
     * @return array<string, mixed>
     */
    private function lastSubscriptionRequest(): array
    {
        $recorded = Http::recorded();

        $this->assertNotEmpty($recorded, 'Expected a call to Lemon Squeezy.');

        /** @var array<string, mixed> $data */
        $data = $recorded->last()[0]->data();

        return $data;
    }

    /**
     * Answer the one Lemon Squeezy endpoint every subscription action goes
     * through — DELETE to cancel, PATCH to resume, GET for the card page — with
     * the attributes the package syncs back onto the row.
     *
     * `product_id` and `variant_id` are always present because Subscription::sync
     * reads them unconditionally, and a fake that omitted them would fail on an
     * undefined key rather than on the thing under test.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function fakeSubscriptionApi(Subscription $subscription, array $attributes = []): void
    {
        config(['lemon-squeezy.api_key' => 'test-api-key']);

        Http::fake([
            LemonSqueezy::API.'/subscriptions/'.$subscription->lemon_squeezy_id => Http::response([
                'data' => [
                    'id' => $subscription->lemon_squeezy_id,
                    'attributes' => [
                        'status' => $subscription->status,
                        'product_id' => $subscription->product_id,
                        'variant_id' => $subscription->variant_id,
                        ...$attributes,
                    ],
                ],
            ]),
        ]);
    }

    /**
     * Give the user a Lemon Squeezy subscription, alongside the one customer row
     * a real account has.
     *
     * Built with the package's factory but saved by hand: its afterCreating hook
     * creates a customer per subscription, and lemon_squeezy_customers is unique
     * on (billable_id, billable_type).
     */
    private function subscribe(
        User $user,
        string $variantId,
        string $status = Subscription::STATUS_ACTIVE,
        ?DateTimeInterface $endsAt = null,
        ?DateTimeInterface $renewsAt = null,
        ?string $cardBrand = 'visa',
        ?string $cardLastFour = '4242',
        string $productId = 'prod_pro',
    ): Subscription {
        $this->customerFor($user);

        $subscription = Subscription::factory()->make([
            'billable_id' => $user->id,
            'billable_type' => $user->getMorphClass(),
            'type' => Subscription::DEFAULT_TYPE,
            'lemon_squeezy_id' => (string) fake()->unique()->randomNumber(8),
            'status' => $status,
            'product_id' => $productId,
            'variant_id' => $variantId,
            'card_brand' => $cardBrand,
            'card_last_four' => $cardLastFour,
            'renews_at' => $renewsAt,
            'ends_at' => $endsAt,
        ]);

        $subscription->save();

        return $subscription;
    }

    /**
     * Give the user a paid order, the way handleOrderCreated would have.
     *
     * `identifier` is set by hand because the package's factory leaves it out
     * and the column is a non-null unique uuid.
     */
    private function order(
        User $user,
        string $lemonSqueezyId,
        int $total = 1900,
        int $orderNumber = 1,
        string $orderedAt = '2026-06-01 10:00:00',
        string $status = Order::STATUS_PAID,
        ?string $receiptUrl = null,
    ): Order {
        $this->customerFor($user);

        $order = Order::factory()->make([
            'billable_id' => $user->id,
            'billable_type' => $user->getMorphClass(),
            'lemon_squeezy_id' => $lemonSqueezyId,
            'identifier' => fake()->unique()->uuid(),
            'customer_id' => '800001',
            'product_id' => 'prod_droneverse_pro',
            'variant_id' => 'var_pro_monthly',
            'order_number' => $orderNumber,
            'currency' => 'USD',
            'subtotal' => $total,
            'discount_total' => 0,
            'tax' => 0,
            'total' => $total,
            'status' => $status,
            'receipt_url' => $receiptUrl ?? 'https://app.lemonsqueezy.com/my-orders/'.$lemonSqueezyId,
            'refunded' => false,
            'refunded_at' => null,
            'ordered_at' => $orderedAt,
        ]);

        $order->save();

        return $order;
    }

    private function customerFor(User $user): void
    {
        Customer::query()->firstOrCreate([
            'billable_id' => $user->id,
            'billable_type' => $user->getMorphClass(),
        ], [
            'lemon_squeezy_id' => (string) fake()->unique()->randomNumber(8),
        ]);
    }
}
