<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\Plan;
use App\Models\User;
use DateTimeInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use LemonSqueezy\Laravel\Customer;
use LemonSqueezy\Laravel\LemonSqueezy;
use LemonSqueezy\Laravel\Subscription;
use Tests\TestCase;

final class PricingTest extends TestCase
{
    use RefreshDatabase;

    private const string CHECKOUT_URL = 'https://droneverse.lemonsqueezy.com/checkout/custom/8f2c1d';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'plans.prices' => [
                'pro' => [
                    'monthly' => 'var_pro_monthly',
                    'yearly' => 'var_pro_yearly',
                ],
                /*
                 * Half-priced on purpose. Team sells itself now, and listing
                 * only its monthly variant keeps the per-period answers under
                 * test: the tier is buyable, and one of its two periods is not.
                 */
                'team' => ['monthly' => 'var_team_monthly'],
            ],
            'plans.sales_email' => 'sales@example.test',
        ]);

        /*
         * The pricing page itself quotes config/plans.php and reaches nobody.
         * Only POST /checkout talks to Lemon Squeezy, and only the tests that
         * mean to fake the answer.
         */
        Http::preventStrayRequests();
    }

    public function test_a_guest_can_read_the_pricing_page(): void
    {
        $this->get(route('pricing'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('pricing')
                ->has('plans', count(Plan::cases()))
                ->where('salesEmail', 'sales@example.test'));

        Http::assertNothingSent();
    }

    /**
     * An environment with no Lemon Squeezy credentials — every local checkout,
     * every test run, and any deployment set up before the store was — still
     * renders the whole page. `configured` is false, which the page reads as
     * "the upgrade buttons stay disabled": presentation only, since
     * ResolveCheckoutPrice is the guard that actually refuses to sell.
     */
    public function test_the_page_renders_where_checkout_cannot_open_at_all(): void
    {
        $this->get(route('pricing'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('lemonSqueezy.configured', false));
    }

    /**
     * Both values are needed to mint a checkout, so either one missing has to
     * report the same thing. Lemon.js takes no publishable key of its own, which
     * is why this one boolean is the whole of what the browser is told.
     */
    public function test_checkout_is_reported_as_unconfigured_unless_both_the_store_and_the_key_are_set(): void
    {
        foreach ([
            ['api_key' => 'test-api-key', 'store' => null],
            ['api_key' => null, 'store' => 'droneverse'],
            ['api_key' => '', 'store' => 'droneverse'],
            ['api_key' => 'test-api-key', 'store' => ''],
        ] as $configuration) {
            config([
                'lemon-squeezy.api_key' => $configuration['api_key'],
                'lemon-squeezy.store' => $configuration['store'],
            ]);

            $this->get(route('pricing'))
                ->assertInertia(fn ($page) => $page->where('lemonSqueezy.configured', false));
        }

        config(['lemon-squeezy.api_key' => 'test-api-key', 'lemon-squeezy.store' => 'droneverse']);

        $this->get(route('pricing'))
            ->assertInertia(fn ($page) => $page->where('lemonSqueezy.configured', true));
    }

    public function test_a_guest_is_sent_to_sign_up_rather_than_to_checkout(): void
    {
        $this->get(route('pricing'))
            ->assertInertia(fn ($page) => $page
                ->where('plans.0.cta.action', 'signup')
                ->where('plans.1.cta.action', 'signup'));
    }

    public function test_a_starter_pilot_is_offered_checkout_on_pro(): void
    {
        $this->actingAs(User::factory()->create())
            ->get(route('pricing'))
            ->assertInertia(fn ($page) => $page
                ->where('plans.0.isCurrent', true)
                ->where('plans.0.cta.action', 'current')
                ->where('plans.1.value', 'pro')
                ->where('plans.1.cta.action', 'checkout')
                ->where('plans.1.isPopular', true));
    }

    public function test_a_pro_pilot_is_not_sold_pro_again(): void
    {
        $this->actingAs(User::factory()->onPlan(Plan::Pro)->create())
            ->get(route('pricing'))
            ->assertInertia(fn ($page) => $page
                ->where('plans.1.cta.action', 'current')
                ->where('plans.1.isPopular', false)
                // Starter is beneath them, and saying so beats an upgrade
                // button pointing downhill.
                ->where('plans.0.cta.action', 'included'));
    }

    /**
     * The card quotes its price from `plans.amounts` and its button from
     * `plans.prices`. An environment with the first and not the second — every
     * environment with no Lemon Squeezy catalogue behind it — must still render
     * honest copy above a button that refuses.
     */
    public function test_an_unpriced_tier_is_not_for_sale_however_confidently_it_is_quoted(): void
    {
        config(['plans.prices.pro' => []]);

        $this->actingAs(User::factory()->create())
            ->get(route('pricing'))
            ->assertInertia(fn ($page) => $page
                ->where('plans.1.value', 'pro')
                ->where('plans.1.cta.action', 'unavailable')
                // The copy still stands; only the button is off.
                ->where('plans.1.prices.monthly.formatted', '$19'));
    }

    /**
     * Purchasability is a question about one billing period, not about the tier.
     *
     * A store is built one variant at a time, so a tier with a monthly ID and no
     * yearly one is the ordinary state of a half-migrated environment rather
     * than an exotic one. The card must stay buyable — monthly is genuinely for
     * sale — while saying, per period, which of them a checkout can be opened
     * on. The page toggles between periods without asking the server again, so
     * it cannot work that out for itself unless it is told here.
     */
    public function test_a_billing_period_with_no_configured_price_is_not_for_sale_though_its_sibling_is(): void
    {
        config(['plans.prices.pro' => ['monthly' => 'var_pro_monthly']]);

        $this->actingAs(User::factory()->create())
            ->get(route('pricing'))
            ->assertInertia(fn ($page) => $page
                ->where('plans.1.value', 'pro')
                ->where('plans.1.cta.action', 'checkout')
                ->where('plans.1.prices.monthly.purchasable', true)
                // Quoted, discount and all, and not for sale.
                ->where('plans.1.prices.yearly.formatted', '$190')
                ->where('plans.1.prices.yearly.savingPercent', 17)
                ->where('plans.1.prices.yearly.purchasable', false));
    }

    /**
     * The mirror image, and the one that bites hardest: the page opens on
     * monthly, so an environment with only a yearly ID configured would
     * otherwise show a working upgrade button over the one period nobody can
     * buy.
     */
    public function test_the_period_the_page_opens_on_is_marked_unsellable_when_only_its_sibling_is_priced(): void
    {
        config(['plans.prices.pro' => ['yearly' => 'var_pro_yearly']]);

        $this->actingAs(User::factory()->create())
            ->get(route('pricing'))
            ->assertInertia(fn ($page) => $page
                ->where('plans.1.cta.action', 'checkout')
                ->where('plans.1.prices.monthly.purchasable', false)
                ->where('plans.1.prices.yearly.purchasable', true));
    }

    /**
     * A tier nobody may buy prices nothing they may buy either, so the two
     * answers agree rather than contradicting each other on the same card.
     */
    public function test_an_unpriced_tier_marks_every_period_unsellable(): void
    {
        config(['plans.prices.pro' => []]);

        $this->actingAs(User::factory()->create())
            ->get(route('pricing'))
            ->assertInertia(fn ($page) => $page
                ->where('plans.1.cta.action', 'unavailable')
                ->where('plans.1.prices.monthly.purchasable', false)
                ->where('plans.1.prices.yearly.purchasable', false));
    }

    /**
     * A visitor with no account holds no plan, so nothing may be badged as the
     * one they are on. Starter is what they would get by signing up, which the
     * button already says.
     */
    public function test_a_guest_is_not_told_that_starter_is_their_current_plan(): void
    {
        $this->get(route('pricing'))
            ->assertInertia(fn ($page) => $page
                ->where('plans.0.value', 'starter')
                ->where('plans.0.isCurrent', false)
                ->where('plans.0.cta.action', 'signup'));
    }

    /**
     * Team is bought the same way Pro is. Enterprise is the only tier a button
     * cannot buy, and not because of what has shipped: a private deployment and
     * an SLA are terms, and there is no amount to charge until they are agreed.
     */
    public function test_team_is_bought_from_the_page_and_only_enterprise_points_at_sales(): void
    {
        $this->actingAs(User::factory()->create())
            ->get(route('pricing'))
            ->assertInertia(fn ($page) => $page
                ->where('plans.2.value', 'team')
                ->where('plans.2.cta.action', 'checkout')
                ->where('plans.2.cta.label', 'Upgrade to Team')
                ->where('plans.2.prices.monthly.purchasable', true)
                // Priced in the copy, absent from the store, so not for sale.
                ->where('plans.2.prices.yearly.purchasable', false)
                ->where('plans.3.value', 'enterprise')
                ->where('plans.3.cta.action', 'contact'));
    }

    /**
     * A subscriber's buttons move the subscription they have. The alternative is
     * a second checkout, which bills them twice for one account and grants
     * nothing the first subscription did not.
     */
    public function test_a_subscriber_is_offered_a_switch_rather_than_a_second_checkout(): void
    {
        $user = User::factory()->create();
        $this->subscribe($user, 'var_pro_monthly');

        $this->actingAs($user)
            ->get(route('pricing'))
            ->assertInertia(fn ($page) => $page
                ->where('plans.1.value', 'pro')
                ->where('plans.1.cta.action', 'current')
                ->where('plans.2.value', 'team')
                ->where('plans.2.cta.action', 'switch')
                ->where('plans.2.cta.label', 'Upgrade to Team'));
    }

    /**
     * Downhill too. A Team subscriber who wants Pro is switching, not being told
     * they already have it — which is what the same card says to a pilot holding
     * Team through a comped account, where there is no subscription to move.
     */
    public function test_a_subscriber_may_switch_down_a_tier_where_a_comped_account_may_not(): void
    {
        $subscriber = User::factory()->create();
        $this->subscribe($subscriber, 'var_team_monthly');

        $this->actingAs($subscriber)
            ->get(route('pricing'))
            ->assertInertia(fn ($page) => $page
                ->where('plans.1.value', 'pro')
                ->where('plans.1.cta.action', 'switch')
                ->where('plans.1.cta.label', 'Switch to Pro'));

        $this->actingAs(User::factory()->onPlan(Plan::Team)->create())
            ->get(route('pricing'))
            ->assertInertia(fn ($page) => $page
                ->where('plans.1.cta.action', 'included'));
    }

    /**
     * A cancelled subscription is still valid through its grace period, so it is
     * neither switchable — repricing something scheduled to end takes money for
     * a plan the pilot has said they do not want — nor a state to sell a second
     * subscription into, which would leave two of them billing one account.
     * Resuming unblocks both, and it lives on the billing page.
     */
    public function test_a_subscription_winding_down_is_sent_to_billing_rather_than_to_either_button(): void
    {
        $user = User::factory()->create();
        $this->subscribe(
            $user,
            'var_pro_monthly',
            status: Subscription::STATUS_CANCELLED,
            endsAt: now()->addWeek(),
        );

        $this->actingAs($user)
            ->get(route('pricing'))
            ->assertInertia(fn ($page) => $page
                ->where('plans.2.value', 'team')
                ->where('plans.2.cta.action', 'manage')
                ->where('plans.2.cta.label', 'Manage subscription'));
    }

    /**
     * The pricing page routes a subscriber to the swap endpoint, and this is
     * what makes that true of a request that skipped the page. Two live
     * subscriptions means two charges and two renewal dates for an account that
     * was already entitled to everything the second one sells.
     */
    public function test_checkout_refuses_to_sell_a_second_subscription(): void
    {
        $this->fakeCheckoutApi();

        $user = User::factory()->create();
        $this->subscribe($user, 'var_pro_monthly');

        $this->actingAs($user)
            ->postJson(route('checkout.store'), ['plan' => 'team', 'variant' => 'monthly'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('plan');

        Http::assertNothingSent();
    }

    /**
     * A pilot whose subscription has expired holds nothing, so they buy rather
     * than switch — which is the same answer ResumeSubscription gives once
     * `ends_at` has passed.
     */
    public function test_an_expired_subscription_is_bought_again_rather_than_switched(): void
    {
        $this->fakeCheckoutApi();

        $user = User::factory()->create();
        $this->subscribe($user, 'var_pro_monthly', status: Subscription::STATUS_EXPIRED);

        $this->actingAs($user)
            ->get(route('pricing'))
            ->assertInertia(fn ($page) => $page
                ->where('plans.1.value', 'pro')
                ->where('plans.1.cta.action', 'checkout'));

        $this->actingAs($user)
            ->postJson(route('checkout.store'), ['plan' => 'pro', 'variant' => 'monthly'])
            ->assertOk();
    }

    public function test_the_annual_saving_is_computed_from_the_prices_it_describes(): void
    {
        $this->get(route('pricing'))
            ->assertInertia(fn ($page) => $page
                ->where('plans.1.prices.monthly.formatted', '$19')
                ->where('plans.1.prices.yearly.formatted', '$190')
                ->where('plans.1.prices.yearly.savingPercent', 17)
                ->where('plans.1.prices.monthly.savingPercent', null));
    }

    public function test_the_comparison_grid_marks_unbuilt_capabilities(): void
    {
        $this->get(route('pricing'))
            ->assertInertia(fn ($page) => $page
                ->where('comparison.0.value', 'python_runtime')
                ->where('comparison.0.available', false)
                // Starter, Pro, Team, Enterprise.
                ->where('comparison.0.plans', [false, true, true, true]));
    }

    public function test_the_client_is_never_handed_a_variant_id(): void
    {
        $response = $this->actingAs(User::factory()->create())->get(route('pricing'));

        $response->assertOk();
        $response->assertDontSee('var_pro_monthly');
        $response->assertDontSee('var_team_monthly');
    }

    public function test_checkout_requires_an_account(): void
    {
        $this->post(route('checkout.store'), ['plan' => 'pro', 'variant' => 'monthly'])
            ->assertRedirect(route('login'));
    }

    /**
     * The response is a URL now rather than an option bag for a client-side SDK
     * to assemble a checkout from. The browser is handed something it cannot
     * alter the price of: it never learns a variant ID, and the URL is already
     * scoped to this buyer and this variant by the time it leaves the server.
     */
    public function test_checkout_answers_with_a_url_minted_for_the_resolved_variant(): void
    {
        $this->fakeCheckoutApi();

        $user = User::factory()->create();

        $response = $this->actingAs($user)
            ->postJson(route('checkout.store'), ['plan' => 'pro', 'variant' => 'yearly']);

        $response->assertOk();
        $response->assertExactJson(['checkout' => ['url' => self::CHECKOUT_URL]]);

        $sent = $this->lastRequest();
        $attributes = $sent->data()['data']['attributes'];
        $relationships = $sent->data()['data']['relationships'];

        $this->assertSame(LemonSqueezy::API.'/checkouts', $sent->url());
        $this->assertSame('var_pro_yearly', $relationships['variant']['data']['id']);
        $this->assertSame('droneverse', $relationships['store']['data']['id']);

        /*
         * Sorted before it is compared because the package assembles this array
         * in its own order and only the pairs matter. `subscription_type` is
         * what makes the resulting webhook record a subscription against this
         * billable, and it is why StartCheckout calls subscribe() rather than
         * checkout(); `plan` and `variant` are ours, carried along for anyone
         * reading the payload later.
         */
        $custom = $attributes['checkout_data']['custom'];
        ksort($custom);

        $this->assertSame([
            'billable_id' => (string) $user->id,
            'billable_type' => $user->getMorphClass(),
            'plan' => 'pro',
            'subscription_type' => 'default',
            'variant' => 'yearly',
        ], $custom);

        /*
         * The overlay needs this; without it the URL only works as a full-page
         * navigation.
         */
        $this->assertTrue($attributes['checkout_options']['embed']);
    }

    /**
     * A redirect URL navigates the browser away the instant Lemon Squeezy has
     * the money, which tears the pricing page down before its Checkout.Success
     * handler can poll for the entitlement — and the plan is granted by a webhook
     * that has not necessarily arrived, so the buyer lands on a fresh page still
     * showing the plan they just paid to leave.
     */
    public function test_checkout_leaves_the_browser_on_the_pricing_page(): void
    {
        $this->fakeCheckoutApi();

        $this->actingAs(User::factory()->create())
            ->postJson(route('checkout.store'), ['plan' => 'pro', 'variant' => 'monthly'])
            ->assertOk();

        $this->assertArrayNotHasKey(
            'redirect_url',
            $this->lastRequest()->data()['data']['attributes']['product_options'],
        );
    }

    /**
     * Minting a checkout is a live API call, which is new: the old Paddle path
     * built its option bag locally and could not fail. An unset store, an unset
     * key or a provider outage must not reach the pilot as a 500, and must not
     * name our configuration in the message it does reach them as.
     */
    public function test_an_unconfigured_store_is_a_validation_error_rather_than_a_five_hundred(): void
    {
        $this->assertEmpty(config('lemon-squeezy.store'));

        $response = $this->actingAs(User::factory()->create())
            ->postJson(route('checkout.store'), ['plan' => 'pro', 'variant' => 'monthly']);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('plan');
        $response->assertDontSee('store', escape: false);

        /*
         * The store is read before anything leaves the building, so a
         * misconfigured environment does not even spend the round trip.
         */
        Http::assertNothingSent();
    }

    public function test_a_provider_outage_is_a_validation_error_rather_than_a_five_hundred(): void
    {
        config(['lemon-squeezy.api_key' => 'test-api-key', 'lemon-squeezy.store' => 'droneverse']);

        Http::fake([
            LemonSqueezy::API.'/checkouts' => Http::response([
                'errors' => [['detail' => 'Service unavailable.', 'status' => '503']],
            ], 503),
        ]);

        $this->actingAs(User::factory()->create())
            ->postJson(route('checkout.store'), ['plan' => 'pro', 'variant' => 'monthly'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('plan');
    }

    public function test_checkout_refuses_a_billing_period_the_plan_does_not_sell(): void
    {
        $this->fakeCheckoutApi();

        $this->actingAs(User::factory()->create())
            ->postJson(route('checkout.store'), ['plan' => 'pro', 'variant' => 'weekly'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('plan');

        Http::assertNothingSent();
    }

    /**
     * Enterprise is negotiated, so there is no price for a card form to charge
     * however the request is shaped. Enforced where it matters rather than
     * trusted to the pricing page's markup.
     */
    public function test_checkout_refuses_a_sales_led_tier(): void
    {
        $this->fakeCheckoutApi();

        $this->actingAs(User::factory()->create())
            ->postJson(route('checkout.store'), ['plan' => 'enterprise', 'variant' => 'monthly'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('plan');

        Http::assertNothingSent();
    }

    public function test_checkout_refuses_a_tier_with_no_configured_price(): void
    {
        $this->fakeCheckoutApi();

        config(['plans.prices.pro' => []]);

        $this->actingAs(User::factory()->create())
            ->postJson(route('checkout.store'), ['plan' => 'pro', 'variant' => 'monthly'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('plan');

        Http::assertNothingSent();
    }

    public function test_checkout_refuses_a_plan_that_does_not_exist(): void
    {
        $this->actingAs(User::factory()->create())
            ->postJson(route('checkout.store'), ['plan' => 'platinum', 'variant' => 'monthly'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('plan');
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
    ): Subscription {
        Customer::query()->create([
            'billable_id' => $user->id,
            'billable_type' => $user->getMorphClass(),
            'lemon_squeezy_id' => (string) fake()->unique()->randomNumber(8),
        ]);

        $subscription = Subscription::factory()->make([
            'billable_id' => $user->id,
            'billable_type' => $user->getMorphClass(),
            'type' => Subscription::DEFAULT_TYPE,
            'lemon_squeezy_id' => (string) fake()->unique()->randomNumber(8),
            'status' => $status,
            'product_id' => 'prod_droneverse',
            'variant_id' => $variantId,
            'ends_at' => $endsAt,
        ]);

        $subscription->save();

        return $subscription;
    }

    /**
     * The one request the checkout endpoint made, asserted on rather than
     * matched inside a closure so a mismatch reports which field was wrong.
     */
    private function lastRequest(): Request
    {
        $recorded = Http::recorded();

        $this->assertCount(1, $recorded, 'Checkout should mint its URL with exactly one call.');

        return $recorded->first()[0];
    }

    /**
     * Configure a store and answer the one endpoint that mints a checkout.
     */
    private function fakeCheckoutApi(): void
    {
        config(['lemon-squeezy.api_key' => 'test-api-key', 'lemon-squeezy.store' => 'droneverse']);

        Http::fake([
            LemonSqueezy::API.'/checkouts' => Http::response([
                'data' => [
                    'type' => 'checkouts',
                    'id' => 'chk_test',
                    'attributes' => ['url' => self::CHECKOUT_URL],
                ],
            ]),
        ]);
    }
}
