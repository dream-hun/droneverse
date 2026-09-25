<?php

declare(strict_types=1);

use App\Enums\Plan;
use App\Enums\SubscriptionStatus;
use App\Models\Customer;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia;

const CHECKOUT_URL = 'https://www.creem.io/payment/ch_4l0N34kxo16AhRKUHFUuXr';
const CHECKOUT_ENDPOINT = 'https://test-api.creem.io/v1/checkouts';

beforeEach(function (): void {
    config([
        'plans.prices' => [
            'pro' => [
                'monthly' => 'prod_pro_monthly',
                'yearly' => 'prod_pro_yearly',
            ],
            /*
             * Half-priced on purpose. Team sells itself now, and listing
             * only its monthly product keeps the per-period answers under
             * test: the tier is buyable, and one of its two periods is not.
             */
            'team' => ['monthly' => 'prod_team_monthly'],
        ],
    ]);

    /*
     * The pricing page itself quotes config/plans.php and reaches nobody.
     * Only POST /checkout talks to Creem, and only the tests that mean to
     * fake the answer.
     */
    Http::preventStrayRequests();
});

test('a guest can read the pricing page', function (): void {
    $this->get(route('pricing'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->component('pricing')
            ->has('plans', count(Plan::cases())));

    Http::assertNothingSent();
});

/**
 * An environment with no Creem credentials — every local checkout, every test
 * run, and any deployment set up before the account was — still renders the
 * whole page. `configured` is false, which the page reads as "the upgrade
 * buttons stay disabled": presentation only, since ResolveCheckoutPrice is
 * the guard that actually refuses to sell.
 */
test('the page renders where checkout cannot open at all', function (): void {
    $this->get(route('pricing'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page->where('creem.configured', false));
});

/**
 * The API key is the whole of what makes checkout possible: Creem needs no
 * store identifier, its embed script takes no publishable key, and the key's
 * own prefix decides whether test or live products are being sold. So one
 * boolean is the whole of what the browser is told.
 */
test('checkout is reported as unconfigured unless the api key is set', function (): void {
    foreach ([null, ''] as $apiKey) {
        config(['creem.api_key' => $apiKey]);

        $this->get(route('pricing'))
            ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page->where('creem.configured', false));
    }

    config(['creem.api_key' => 'creem_test_key']);

    $this->get(route('pricing'))
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page->where('creem.configured', true));
});

test('a guest is sent to sign up rather than to checkout', function (): void {
    $this->get(route('pricing'))
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->where('plans.0.cta.action', 'signup')
            ->where('plans.1.cta.action', 'signup'));
});

test('a starter pilot is offered checkout on pro', function (): void {
    $this->actingAs(User::factory()->create())
        ->get(route('pricing'))
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->where('plans.0.isCurrent', true)
            ->where('plans.0.cta.action', 'current')
            ->where('plans.1.value', 'pro')
            ->where('plans.1.cta.action', 'checkout')
            ->where('plans.1.isPopular', true));
});

test('a pro pilot is not sold pro again', function (): void {
    $this->actingAs(User::factory()->onPlan(Plan::Pro)->create())
        ->get(route('pricing'))
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->where('plans.1.cta.action', 'current')
            ->where('plans.1.isPopular', false)
            // Starter is beneath them, and saying so beats an upgrade
            // button pointing downhill.
            ->where('plans.0.cta.action', 'included'));
});

/**
 * The card quotes its price from `plans.amounts` and its button from
 * `plans.prices`. An environment with the first and not the second — every
 * environment with no Creem catalogue behind it — must still render honest
 * copy above a button that refuses.
 */
test('an unpriced tier is not for sale however confidently it is quoted', function (): void {
    config(['plans.prices.pro' => []]);

    $this->actingAs(User::factory()->create())
        ->get(route('pricing'))
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->where('plans.1.value', 'pro')
            ->where('plans.1.cta.action', 'unavailable')
            // The copy still stands; only the button is off.
            ->where('plans.1.prices.monthly.formatted', '$19'));
});

/**
 * Purchasability is a question about one billing period, not about the tier.
 *
 * A store is built one product at a time, so a tier with a monthly ID and no
 * yearly one is the ordinary state of a half-migrated environment rather
 * than an exotic one. The card must stay buyable — monthly is genuinely for
 * sale — while saying, per period, which of them a checkout can be opened
 * on. The page toggles between periods without asking the server again, so
 * it cannot work that out for itself unless it is told here.
 */
test('a billing period with no configured price is not for sale though its sibling is', function (): void {
    config(['plans.prices.pro' => ['monthly' => 'prod_pro_monthly']]);

    $this->actingAs(User::factory()->create())
        ->get(route('pricing'))
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->where('plans.1.value', 'pro')
            ->where('plans.1.cta.action', 'checkout')
            ->where('plans.1.prices.monthly.purchasable', true)
            // Quoted, discount and all, and not for sale.
            ->where('plans.1.prices.yearly.formatted', '$190')
            ->where('plans.1.prices.yearly.savingPercent', 17)
            ->where('plans.1.prices.yearly.purchasable', false));
});

/**
 * The mirror image, and the one that bites hardest: the page opens on
 * monthly, so an environment with only a yearly ID configured would
 * otherwise show a working upgrade button over the one period nobody can
 * buy.
 */
test('the period the page opens on is marked unsellable when only its sibling is priced', function (): void {
    config(['plans.prices.pro' => ['yearly' => 'prod_pro_yearly']]);

    $this->actingAs(User::factory()->create())
        ->get(route('pricing'))
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->where('plans.1.cta.action', 'checkout')
            ->where('plans.1.prices.monthly.purchasable', false)
            ->where('plans.1.prices.yearly.purchasable', true));
});

/**
 * A tier nobody may buy prices nothing they may buy either, so the two
 * answers agree rather than contradicting each other on the same card.
 */
test('an unpriced tier marks every period unsellable', function (): void {
    config(['plans.prices.pro' => []]);

    $this->actingAs(User::factory()->create())
        ->get(route('pricing'))
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->where('plans.1.cta.action', 'unavailable')
            ->where('plans.1.prices.monthly.purchasable', false)
            ->where('plans.1.prices.yearly.purchasable', false));
});

/**
 * A visitor with no account holds no plan, so nothing may be badged as the
 * one they are on. Starter is what they would get by signing up, which the
 * button already says.
 */
test('a guest is not told that starter is their current plan', function (): void {
    $this->get(route('pricing'))
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->where('plans.0.value', 'starter')
            ->where('plans.0.isCurrent', false)
            ->where('plans.0.cta.action', 'signup'));
});

/**
 * Team is bought the same way Pro is: a classroom of ten is a card payment,
 * and every tier on this page is now sold from a button.
 */
test('team is bought from the page like pro', function (): void {
    $this->actingAs(User::factory()->create())
        ->get(route('pricing'))
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->where('plans.2.value', 'team')
            ->where('plans.2.cta.action', 'checkout')
            ->where('plans.2.cta.label', 'Upgrade to Team')
            ->where('plans.2.prices.monthly.purchasable', true)
            // Priced in the copy, absent from the store, so not for sale.
            ->where('plans.2.prices.yearly.purchasable', false));
});

/**
 * A subscriber's buttons move the subscription they have. The alternative is
 * a second checkout, which bills them twice for one account and grants
 * nothing the first subscription did not.
 */
test('a subscriber is offered a switch rather than a second checkout', function (): void {
    $user = User::factory()->create();
    pricingSubscribe($user, 'prod_pro_monthly');

    $this->actingAs($user)
        ->get(route('pricing'))
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->where('plans.1.value', 'pro')
            ->where('plans.1.cta.action', 'current')
            ->where('plans.2.value', 'team')
            ->where('plans.2.cta.action', 'switch')
            ->where('plans.2.cta.label', 'Upgrade to Team'));
});

/**
 * Downhill too. A Team subscriber who wants Pro is switching, not being told
 * they already have it — which is what the same card says to a pilot holding
 * Team through a comped account, where there is no subscription to move.
 */
test('a subscriber may switch down a tier where a comped account may not', function (): void {
    $subscriber = User::factory()->create();
    pricingSubscribe($subscriber, 'prod_team_monthly');

    $this->actingAs($subscriber)
        ->get(route('pricing'))
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->where('plans.1.value', 'pro')
            ->where('plans.1.cta.action', 'switch')
            ->where('plans.1.cta.label', 'Switch to Pro'));

    $this->actingAs(User::factory()->onPlan(Plan::Team)->create())
        ->get(route('pricing'))
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->where('plans.1.cta.action', 'included'));
});

/**
 * A cancelled subscription is still valid through its grace period, so it is
 * neither switchable — repricing something scheduled to end takes money for
 * a plan the pilot has said they do not want — nor a state to sell a second
 * subscription into, which would leave two of them billing one account.
 * Resuming unblocks both, and it lives on the billing page.
 */
test('a subscription winding down is sent to billing rather than to either button', function (): void {
    $user = User::factory()->create();
    pricingSubscribe(
        $user,
        'prod_pro_monthly',
        status: SubscriptionStatus::ScheduledCancel,
        endsAt: now()->addWeek(),
    );

    $this->actingAs($user)
        ->get(route('pricing'))
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->where('plans.2.value', 'team')
            ->where('plans.2.cta.action', 'manage')
            ->where('plans.2.cta.label', 'Manage subscription'));
});

/**
 * The pricing page routes a subscriber to the swap endpoint, and this is
 * what makes that true of a request that skipped the page. Two live
 * subscriptions means two charges and two renewal dates for an account that
 * was already entitled to everything the second one sells.
 */
test('checkout refuses to sell a second subscription', function (): void {
    fakeCheckoutApi();

    $user = User::factory()->create();
    pricingSubscribe($user, 'prod_pro_monthly');

    $this->actingAs($user)
        ->postJson(route('checkout.store'), ['plan' => 'team', 'variant' => 'monthly'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('plan');

    Http::assertNothingSent();
});

/**
 * A pilot whose subscription has expired holds nothing, so they buy rather
 * than switch — which is the same answer ResumeSubscription gives once the
 * period has run out.
 */
test('an expired subscription is bought again rather than switched', function (): void {
    fakeCheckoutApi();

    $user = User::factory()->create();
    pricingSubscribe($user, 'prod_pro_monthly', status: SubscriptionStatus::Expired);

    $this->actingAs($user)
        ->get(route('pricing'))
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->where('plans.1.value', 'pro')
            ->where('plans.1.cta.action', 'checkout'));

    $this->actingAs($user)
        ->postJson(route('checkout.store'), ['plan' => 'pro', 'variant' => 'monthly'])
        ->assertOk();
});

test('the annual saving is computed from the prices it describes', function (): void {
    $this->get(route('pricing'))
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->where('plans.1.prices.monthly.formatted', '$19')
            ->where('plans.1.prices.yearly.formatted', '$190')
            ->where('plans.1.prices.yearly.savingPercent', 17)
            ->where('plans.1.prices.monthly.savingPercent', null));
});

test('the comparison grid marks unbuilt capabilities', function (): void {
    $this->get(route('pricing'))
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->where('comparison.0.value', 'mission_builder')
            ->where('comparison.0.available', false)
            // Starter, Pro, Team.
            ->where('comparison.0.plans', [false, true, true]));
});

test('the client is never handed a product id', function (): void {
    $response = $this->actingAs(User::factory()->create())->get(route('pricing'));

    $response->assertOk();
    $response->assertDontSee('prod_pro_monthly');
    $response->assertDontSee('prod_team_monthly');
});

test('checkout requires an account', function (): void {
    $this->post(route('checkout.store'), ['plan' => 'pro', 'variant' => 'monthly'])
        ->assertRedirect(route('login'));
});

/**
 * The response is a URL and nothing else. The browser is handed something it
 * cannot alter the price of: it never learns a product ID, and the session is
 * already scoped to this buyer and this product by the time it leaves the
 * server.
 */
test('checkout answers with a url minted for the resolved product', function (): void {
    fakeCheckoutApi();

    $user = User::factory()->create();

    $response = $this->actingAs($user)
        ->postJson(route('checkout.store'), ['plan' => 'pro', 'variant' => 'yearly']);

    $response->assertOk();
    $response->assertExactJson(['checkout' => ['url' => CHECKOUT_URL]]);

    $sent = lastRequest();

    expect($sent->url())->toBe(CHECKOUT_ENDPOINT);
    expect($sent->header('x-api-key'))->toBe(['creem_test_key']);
    expect($sent['product_id'])->toBe('prod_pro_yearly');

    /*
     * Creem copies this onto the subscription it creates and onto every event
     * about it afterwards, which is how a webhook arriving hours later knows
     * whose plan to grant.
     */
    $metadata = Arr::array($sent, 'metadata');
    ksort($metadata);

    expect($metadata)->toBe([
        'billable_id' => (string) $user->id,
        'billable_type' => $user->getMorphClass(),
        'plan' => 'pro',
        'variant' => 'yearly',
    ]);

    /*
     * Locked to the account's own address, so the Creem customer that comes
     * back is this pilot rather than whoever they happened to type.
     */
    expect($sent['customer'])->toBe(['email' => $user->email]);
});

/**
 * The success URL is not where the overlay sends anybody — the page cancels
 * that navigation and makes the same trip as an Inertia visit, which keeps the
 * app mounted underneath. It is there for the buyer whose browser blocked
 * embed.js and paid on Creem's own page, who would otherwise be stranded on it.
 */
test('checkout names the thank you page for a buyer who never framed it', function (): void {
    fakeCheckoutApi();

    $this->actingAs(User::factory()->create())
        ->postJson(route('checkout.store'), ['plan' => 'pro', 'variant' => 'monthly'])
        ->assertOk();

    expect(lastRequest()['success_url'])->toBe(route('subscription.thank-you'));
});

/**
 * Minting a checkout is a live API call. An unset key or a provider outage
 * must not reach the pilot as a 500, and must not name our configuration in
 * the message it does reach them as.
 */
test('an unconfigured account is a validation error rather than a five hundred', function (): void {
    expect(config('creem.api_key'))->toBeEmpty();

    $response = $this->actingAs(User::factory()->create())
        ->postJson(route('checkout.store'), ['plan' => 'pro', 'variant' => 'monthly']);

    $response->assertStatus(422);
    $response->assertJsonValidationErrors('plan');
    $response->assertDontSee('CREEM_API_KEY', escape: false);

    /*
     * The key is read before anything leaves the building, so a misconfigured
     * environment does not even spend the round trip.
     */
    Http::assertNothingSent();
});

test('a provider outage is a validation error rather than a five hundred', function (): void {
    config(['creem.api_key' => 'creem_test_key']);

    Http::fake([
        CHECKOUT_ENDPOINT => Http::response(['error' => 'Service unavailable.'], 503),
    ]);

    $this->actingAs(User::factory()->create())
        ->postJson(route('checkout.store'), ['plan' => 'pro', 'variant' => 'monthly'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('plan');
});

test('checkout refuses a billing period the plan does not sell', function (): void {
    fakeCheckoutApi();

    $this->actingAs(User::factory()->create())
        ->postJson(route('checkout.store'), ['plan' => 'pro', 'variant' => 'weekly'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('plan');

    Http::assertNothingSent();
});

/**
 * Starter is an account rather than a purchase, so there is no price for a
 * card form to charge however the request is shaped. Enforced where it
 * matters rather than trusted to the pricing page's markup.
 */
test('checkout refuses the free tier', function (): void {
    fakeCheckoutApi();

    $this->actingAs(User::factory()->create())
        ->postJson(route('checkout.store'), ['plan' => 'starter', 'variant' => 'monthly'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('plan');

    Http::assertNothingSent();
});

test('checkout refuses a tier with no configured price', function (): void {
    fakeCheckoutApi();

    config(['plans.prices.pro' => []]);

    $this->actingAs(User::factory()->create())
        ->postJson(route('checkout.store'), ['plan' => 'pro', 'variant' => 'monthly'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('plan');

    Http::assertNothingSent();
});

test('checkout refuses a plan that does not exist', function (): void {
    $this->actingAs(User::factory()->create())
        ->postJson(route('checkout.store'), ['plan' => 'platinum', 'variant' => 'monthly'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('plan');
});

/**
 * Give the user a Creem subscription, alongside the one customer row a real
 * account has.
 */
function pricingSubscribe(
    User $user,
    string $productId,
    SubscriptionStatus $status = SubscriptionStatus::Active,
    ?DateTimeInterface $endsAt = null,
): Subscription {
    Customer::factory()->billable($user)->create();

    return Subscription::factory()
        ->billable($user)
        ->selling($productId)
        ->create([
            'status' => $status->value,
            ...($endsAt instanceof DateTimeInterface ? ['current_period_end_at' => $endsAt] : []),
        ]);
}

/**
 * The one request the checkout endpoint made, asserted on rather than
 * matched inside a closure so a mismatch reports which field was wrong.
 */
function lastRequest(): Request
{
    $recorded = Http::recorded();

    expect($recorded)->toHaveCount(1, 'Checkout should mint its URL with exactly one call.');

    return $recorded->sole()[0];
}

/**
 * Configure an API key and answer the one endpoint that mints a checkout.
 *
 * The key is a test-mode one, which is also what points the client at Creem's
 * test host — so CHECKOUT_ENDPOINT is not an arbitrary URL to fake, it is the
 * host that prefix resolves to.
 */
function fakeCheckoutApi(): void
{
    config(['creem.api_key' => 'creem_test_key']);

    Http::fake([
        CHECKOUT_ENDPOINT => Http::response([
            'id' => 'ch_4l0N34kxo16AhRKUHFUuXr',
            'checkout_url' => CHECKOUT_URL,
            'product_id' => 'prod_pro_yearly',
            'status' => 'pending',
        ]),
    ]);
}
