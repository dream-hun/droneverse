<?php

declare(strict_types=1);

use App\Actions\ResolvePlanForUser;
use App\Enums\Feature;
use App\Enums\Plan;
use App\Enums\SubscriptionStatus;
use App\Models\Customer;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia;

beforeEach(function (): void {
    config(['plans.prices' => [
        'pro' => [
            'monthly' => 'prod_pro_monthly',
            'yearly' => 'prod_pro_yearly',
            'monthly_launch' => 'prod_pro_monthly_launch',
        ],
        'team' => ['monthly' => 'prod_team_monthly'],
    ]]);
});

test('a user with nothing resolves to starter', function (): void {
    $user = User::factory()->create();

    expect(resolvePlanFor($user))->toBe(Plan::Starter);
});

test('a guest resolves to starter', function (): void {
    expect(resolve(ResolvePlanForUser::class)->handle(null))->toBe(Plan::Starter);
});

test('a plan override resolves to that plan', function (): void {
    $user = User::factory()->onPlan(Plan::Pro)->create();

    expect(resolvePlanFor($user))->toBe(Plan::Pro);
});

test('a plan override outranks an active subscription', function (): void {
    $user = User::factory()->onPlan(Plan::Team)->create();
    entitlementSubscribe($user, 'prod_pro_monthly');

    expect(resolvePlanFor($user))->toBe(Plan::Team);
});

test('an override naming a retired plan falls through instead of throwing', function (): void {
    $user = User::factory()->create(['plan_override' => 'platinum']);

    expect(resolvePlanFor($user))->toBe(Plan::Starter);
});

/**
 * A Creem subscription names the product it sells on the row itself, so the
 * plan is read straight off `product_id` — a Creem product carries its own
 * price and billing period, so there is no separate price object and no join.
 * Paddle's subscription_items table has no counterpart in this schema, and
 * nothing looks for one.
 */
test('an active subscription resolves through the product on its own row', function (): void {
    $user = User::factory()->create();
    $subscription = entitlementSubscribe($user, 'prod_pro_monthly');

    expect($subscription->product_id)->toBe('prod_pro_monthly');
    expect(resolvePlanFor($user))->toBe(Plan::Pro);
});

test('a trialing subscription still grants its plan', function (): void {
    $user = User::factory()->create();
    entitlementSubscribe($user, 'prod_pro_yearly', SubscriptionStatus::Trialing);

    expect(resolvePlanFor($user))->toBe(Plan::Pro);
});

/**
 * A card that bounced is a payment problem, not a decision to leave. Creem
 * retries on a schedule before it gives up, and locking the catalogue on the
 * first failure would punish an expired card harder than a cancellation.
 *
 * `unpaid` is where that generosity stops: collection has been abandoned by
 * then, and it is Creem's own signal to suspend.
 */
test('a past due subscription still grants its plan and an unpaid one does not', function (): void {
    $pastDue = User::factory()->create();
    entitlementSubscribe($pastDue, 'prod_pro_monthly', SubscriptionStatus::PastDue);

    $unpaid = User::factory()->create();
    entitlementSubscribe($unpaid, 'prod_pro_monthly', SubscriptionStatus::Unpaid);

    expect(resolvePlanFor($pastDue))->toBe(Plan::Pro);
    expect(resolvePlanFor($unpaid))->toBe(Plan::Starter);
});

/**
 * They bought the month, so they keep it. A scheduled cancellation stays valid
 * through the period that was paid for, which is what makes
 * CancelSubscription's end-of-period promise hold without a special case
 * anywhere.
 */
test('a cancelled subscription grants its plan until the paid period runs out', function (): void {
    $user = User::factory()->create();
    entitlementSubscribe($user, 'prod_pro_monthly', SubscriptionStatus::ScheduledCancel, endsAt: now()->addWeek());

    expect(resolvePlanFor($user))->toBe(Plan::Pro);
});

/**
 * Creem moves a scheduled cancellation to `canceled` when the period runs out,
 * but that is a webhook — and a webhook that never lands must not leave
 * somebody entitled forever. The date on the row is what closes that door.
 */
test('a cancelled subscription grants nothing once the period has run out', function (): void {
    $user = User::factory()->create();
    entitlementSubscribe($user, 'prod_pro_monthly', SubscriptionStatus::ScheduledCancel, endsAt: now()->subDay());

    expect(resolvePlanFor($user))->toBe(Plan::Starter);
});

test('an outright cancellation grants nothing whatever its dates say', function (): void {
    $user = User::factory()->create();
    entitlementSubscribe($user, 'prod_pro_monthly', SubscriptionStatus::Canceled, endsAt: now()->addWeek());

    expect(resolvePlanFor($user))->toBe(Plan::Starter);
});

test('an expired subscription grants nothing', function (): void {
    $user = User::factory()->create();
    entitlementSubscribe($user, 'prod_pro_monthly', SubscriptionStatus::Expired);

    expect(resolvePlanFor($user))->toBe(Plan::Starter);
});

/**
 * Creem pauses billing and expects access to stop with it, which is the one
 * place this differs from the Lemon Squeezy integration before it: its free
 * pause left a subscription valid. Nothing in this application pauses a
 * subscription, so the case only arises from the Creem dashboard — and
 * somebody pausing billing there means to stop the service, not to give it
 * away.
 */
test('a paused subscription grants nothing', function (): void {
    $user = User::factory()->create();
    entitlementSubscribe($user, 'prod_pro_monthly', SubscriptionStatus::Paused);

    expect(resolvePlanFor($user))->toBe(Plan::Starter);
});

/**
 * A status Creem adds after this code was written resolves to no case at all,
 * and grants nothing. Failing open here would mean any new lifecycle state —
 * whatever it turns out to mean — handing out a paid plan.
 */
test('a status this application does not recognise grants nothing', function (): void {
    $user = User::factory()->create();
    entitlementSubscribe($user, 'prod_pro_monthly', status: 'something_creem_invented_later');

    expect(resolvePlanFor($user))->toBe(Plan::Starter);
});

test('the most generous of several subscriptions wins', function (): void {
    $user = User::factory()->create();
    entitlementSubscribe($user, 'prod_pro_monthly');
    entitlementSubscribe($user, 'prod_team_monthly', type: 'classroom');

    expect(resolvePlanFor($user))->toBe(Plan::Team);
});

/**
 * The reverse of the case above, asserted separately because the reduction
 * that picks the winner is order-sensitive and the rows come back in
 * insertion order.
 */
test('the most generous subscription wins whichever was bought first', function (): void {
    $user = User::factory()->create();
    entitlementSubscribe($user, 'prod_team_monthly', type: 'classroom');
    entitlementSubscribe($user, 'prod_pro_monthly');

    expect(resolvePlanFor($user))->toBe(Plan::Team);
});

/**
 * An expired Team subscription alongside a live Pro one resolves to Pro:
 * only valid subscriptions are considered, so a plan someone used to hold
 * cannot outrank the one they still pay for.
 */
test('a lapsed subscription does not outrank a live one', function (): void {
    $user = User::factory()->create();
    entitlementSubscribe($user, 'prod_team_monthly', SubscriptionStatus::Expired, type: 'classroom');
    entitlementSubscribe($user, 'prod_pro_monthly');

    expect(resolvePlanFor($user))->toBe(Plan::Pro);
});

test('a subscription to an unrecognised product grants nothing', function (): void {
    $user = User::factory()->create();
    entitlementSubscribe($user, 'prod_some_retired_experiment');

    expect(resolvePlanFor($user))->toBe(Plan::Starter);
});

test('one users subscription does not leak to another', function (): void {
    $subscriber = User::factory()->create();
    entitlementSubscribe($subscriber, 'prod_pro_monthly');

    $bystander = User::factory()->create();

    expect(resolvePlanFor($subscriber))->toBe(Plan::Pro);
    expect(resolvePlanFor($bystander))->toBe(Plan::Starter);
});

test('the model answers feature questions from its plan', function (): void {
    $starter = User::factory()->create();
    $pro = User::factory()->onPlan(Plan::Pro)->create();

    expect($starter->hasFeature(Feature::MissionBuilder))->toBeFalse();
    expect($starter->onPaidPlan())->toBeFalse();
    expect($starter->features())->toBe([]);

    expect($pro->hasFeature(Feature::MissionBuilder))->toBeTrue();
    expect($pro->onPaidPlan())->toBeTrue();
    expect($pro->planCovers(Plan::Starter))->toBeTrue();
});

test('the resolved plan is memoised until forgotten', function (): void {
    $user = User::factory()->create();

    expect($user->plan())->toBe(Plan::Starter);

    $user->plan_override = Plan::Pro->value;
    $user->save();

    expect($user->plan())->toBe(Plan::Starter, 'The memo should survive a change made after resolution.');
    expect($user->forgetPlan()->plan())->toBe(Plan::Pro);
});

test('a gated route rejects starter and admits pro', function (): void {
    Route::middleware(['web', 'auth', 'can:'.Feature::MissionBuilder->value])
        ->get('__test__/builder', fn (): string => 'ok');

    $starter = User::factory()->create();
    $pro = User::factory()->onPlan(Plan::Pro)->create();

    $this->actingAs($starter)->get('__test__/builder')->assertForbidden();
    $this->actingAs($pro)->get('__test__/builder')->assertOk();
});

test('every feature is registered as a gate', function (): void {
    $team = User::factory()->onPlan(Plan::Team)->create();
    $starter = User::factory()->create();

    foreach (Feature::cases() as $feature) {
        expect($team->can($feature->value))->toBeTrue($feature->value);
        expect($starter->can($feature->value))->toBeFalse($feature->value);
    }
});

test('entitlements are shared with the front end', function (): void {
    $user = User::factory()->onPlan(Plan::Pro)->create();

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->where('auth.plan.value', 'pro')
            ->where('auth.plan.label', 'Pro')
            ->where('auth.plan.isPaid', true)
            ->where('auth.features', fn (Collection $features): bool => $features->contains('mission_builder')
                && $features->doesntContain('team_management')));
});

test('guests are shared the starter plan', function (): void {
    $this->get(route('home'))
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->where('auth.plan.value', 'starter')
            ->where('auth.features', []));
});

test('the plan override is never serialised to the client', function (): void {
    $user = User::factory()->onPlan(Plan::Team)->create();

    expect($user->toArray())->not->toHaveKey('plan_override');
});

/**
 * Decision 2: nobody who signed up while the platform was free loses
 * access on the day it stops being free.
 *
 * The migration runs against an empty table in a test database, so it is
 * invoked directly here against accounts that stand in for the pre-launch
 * ones. Anyone already carrying an override — comped, staff, academic — is
 * left exactly as they were.
 */
test('pre launch accounts are grandfathered to pro', function (): void {
    $preLaunch = User::factory()->create();
    $comped = User::factory()->onPlan(Plan::Team)->create();

    $migration = require database_path('migrations/2026_07_28_092306_backfill_pre_launch_users_to_pro.php');

    $this->assertInstanceOf(Migration::class, $migration);
    $this->assertTrue(method_exists($migration, 'up'));

    $migration->up();

    expect(resolvePlanFor($preLaunch))->toBe(Plan::Pro);
    expect(resolvePlanFor($comped))->toBe(Plan::Team);
});

function resolvePlanFor(User $user): Plan
{
    return resolve(ResolvePlanForUser::class)->handle($user->fresh());
}

/**
 * Give the user a Creem subscription to a single product, alongside the one
 * customer row a real account has.
 *
 * The customer is `firstOrCreate`d rather than made per subscription, because
 * creem_customers is unique on (billable_id, billable_type) — a second
 * subscription for the same pilot, which several tests above need, would
 * otherwise collide on it.
 */
function entitlementSubscribe(
    User $user,
    string $productId,
    SubscriptionStatus|string $status = SubscriptionStatus::Active,
    string $type = Subscription::DEFAULT_TYPE,
    ?DateTimeInterface $endsAt = null,
): Subscription {
    Customer::query()->firstOrCreate(
        ['billable_id' => $user->id, 'billable_type' => $user->getMorphClass()],
        ['creem_id' => 'cust_'.fake()->unique()->bothify('??##??##')],
    );

    return Subscription::factory()
        ->billable($user)
        ->selling($productId)
        ->create([
            'type' => $type,
            'status' => $status instanceof SubscriptionStatus ? $status->value : $status,
            ...($endsAt instanceof DateTimeInterface ? ['current_period_end_at' => $endsAt] : []),
        ]);
}
