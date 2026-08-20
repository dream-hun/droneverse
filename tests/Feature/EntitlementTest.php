<?php

declare(strict_types=1);

use App\Actions\ResolvePlanForUser;
use App\Enums\Feature;
use App\Enums\Plan;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Route;
use LemonSqueezy\Laravel\Customer;
use LemonSqueezy\Laravel\Subscription;

beforeEach(function (): void {
    config(['plans.prices' => [
        'pro' => [
            'monthly' => 'var_pro_monthly',
            'yearly' => 'var_pro_yearly',
            'monthly_launch' => 'var_pro_monthly_launch',
        ],
        'team' => ['monthly' => 'var_team_monthly'],
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
    entitlementSubscribe($user, 'var_pro_monthly');

    expect(resolvePlanFor($user))->toBe(Plan::Team);
});

test('an override naming a retired plan falls through instead of throwing', function (): void {
    $user = User::factory()->create(['plan_override' => 'platinum']);

    expect(resolvePlanFor($user))->toBe(Plan::Starter);
});

/**
 * A Lemon Squeezy subscription names the variant it sells on the row itself,
 * so the plan is read straight off `variant_id`. Paddle's subscription_items
 * join has no counterpart in this schema, and nothing looks for one.
 */
test('an active subscription resolves through the variant on its own row', function (): void {
    $user = User::factory()->create();
    $subscription = entitlementSubscribe($user, 'var_pro_monthly');

    expect($subscription->variant_id)->toBe('var_pro_monthly');
    expect(resolvePlanFor($user))->toBe(Plan::Pro);
});

test('a trialing subscription still grants its plan', function (): void {
    $user = User::factory()->create();
    entitlementSubscribe($user, 'var_pro_yearly', Subscription::STATUS_ON_TRIAL);

    expect(resolvePlanFor($user))->toBe(Plan::Pro);
});

/**
 * A card that bounced is a payment problem, not a decision to leave. Lemon
 * Squeezy retries for days before it gives up, and locking the catalogue on
 * the first failure would punish an expired card harder than a cancellation.
 */
test('a past due subscription still grants its plan', function (): void {
    $user = User::factory()->create();
    entitlementSubscribe($user, 'var_pro_monthly', Subscription::STATUS_PAST_DUE);

    expect(resolvePlanFor($user))->toBe(Plan::Pro);
});

/**
 * They bought the month, so they keep it. `valid()` stays true through the
 * grace period after a cancellation, which is what makes CancelSubscription's
 * end-of-period promise hold without a special case anywhere.
 */
test('a cancelled subscription grants its plan until the paid period runs out', function (): void {
    $user = User::factory()->create();
    entitlementSubscribe($user, 'var_pro_monthly', Subscription::STATUS_CANCELLED, endsAt: now()->addWeek());

    expect(resolvePlanFor($user))->toBe(Plan::Pro);
});

test('a cancelled subscription grants nothing once the period has run out', function (): void {
    $user = User::factory()->create();
    entitlementSubscribe($user, 'var_pro_monthly', Subscription::STATUS_CANCELLED, endsAt: now()->subDay());

    expect(resolvePlanFor($user))->toBe(Plan::Starter);
});

test('an expired subscription grants nothing', function (): void {
    $user = User::factory()->create();
    entitlementSubscribe($user, 'var_pro_monthly', Subscription::STATUS_EXPIRED);

    expect(resolvePlanFor($user))->toBe(Plan::Starter);
});

/**
 * Lemon Squeezy pauses in two modes and they mean opposite things. A free
 * pause keeps the pilot on the plan while it stops charging them, so the
 * catalogue stays open; a void pause stops the service as well as the
 * billing. `valid()` distinguishes them and this is where that shows.
 */
test('a free pause keeps the plan and a void pause does not', function (): void {
    $free = User::factory()->create();
    entitlementSubscribe($free, 'var_pro_monthly', Subscription::STATUS_PAUSED, pauseMode: 'free');

    $void = User::factory()->create();
    entitlementSubscribe($void, 'var_pro_monthly', Subscription::STATUS_PAUSED, pauseMode: 'void');

    expect(resolvePlanFor($free))->toBe(Plan::Pro);
    expect(resolvePlanFor($void))->toBe(Plan::Starter);
});

test('the most generous of several subscriptions wins', function (): void {
    $user = User::factory()->create();
    entitlementSubscribe($user, 'var_pro_monthly');
    entitlementSubscribe($user, 'var_team_monthly', type: 'classroom');

    expect(resolvePlanFor($user))->toBe(Plan::Team);
});

/**
 * The reverse of the case above, asserted separately because the reduction
 * that picks the winner is order-sensitive and the rows come back in
 * insertion order.
 */
test('the most generous subscription wins whichever was bought first', function (): void {
    $user = User::factory()->create();
    entitlementSubscribe($user, 'var_team_monthly', type: 'classroom');
    entitlementSubscribe($user, 'var_pro_monthly');

    expect(resolvePlanFor($user))->toBe(Plan::Team);
});

/**
 * An expired Team subscription alongside a live Pro one resolves to Pro:
 * only valid subscriptions are considered, so a plan someone used to hold
 * cannot outrank the one they still pay for.
 */
test('a lapsed subscription does not outrank a live one', function (): void {
    $user = User::factory()->create();
    entitlementSubscribe($user, 'var_team_monthly', Subscription::STATUS_EXPIRED, type: 'classroom');
    entitlementSubscribe($user, 'var_pro_monthly');

    expect(resolvePlanFor($user))->toBe(Plan::Pro);
});

test('a subscription to an unrecognised variant grants nothing', function (): void {
    $user = User::factory()->create();
    entitlementSubscribe($user, 'var_some_retired_experiment');

    expect(resolvePlanFor($user))->toBe(Plan::Starter);
});

test('one users subscription does not leak to another', function (): void {
    $subscriber = User::factory()->create();
    entitlementSubscribe($subscriber, 'var_pro_monthly');

    $bystander = User::factory()->create();

    expect(resolvePlanFor($subscriber))->toBe(Plan::Pro);
    expect(resolvePlanFor($bystander))->toBe(Plan::Starter);
});

test('the model answers feature questions from its plan', function (): void {
    $starter = User::factory()->create();
    $pro = User::factory()->onPlan(Plan::Pro)->create();

    expect($starter->hasFeature(Feature::PythonRuntime))->toBeFalse();
    expect($starter->onPaidPlan())->toBeFalse();
    expect($starter->features())->toBe([]);

    expect($pro->hasFeature(Feature::PythonRuntime))->toBeTrue();
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
    Route::middleware(['web', 'auth', 'can:'.Feature::PythonRuntime->value])
        ->get('__test__/python', fn (): string => 'ok');

    $starter = User::factory()->create();
    $pro = User::factory()->onPlan(Plan::Pro)->create();

    $this->actingAs($starter)->get('__test__/python')->assertForbidden();
    $this->actingAs($pro)->get('__test__/python')->assertOk();
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
        ->assertInertia(fn ($page) => $page
            ->where('auth.plan.value', 'pro')
            ->where('auth.plan.label', 'Pro')
            ->where('auth.plan.isPaid', true)
            ->where('auth.features', fn (Collection $features): bool => $features->contains('python_runtime')
                && $features->doesntContain('team_management')));
});

test('guests are shared the starter plan', function (): void {
    $this->get(route('home'))
        ->assertInertia(fn ($page) => $page
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
    $migration->up();

    expect(resolvePlanFor($preLaunch))->toBe(Plan::Pro);
    expect(resolvePlanFor($comped))->toBe(Plan::Team);
});

function resolvePlanFor(User $user): Plan
{
    return resolve(ResolvePlanForUser::class)->handle($user->fresh());
}

/**
 * Give the user a Lemon Squeezy subscription to a single variant, alongside
 * the one customer row a real account has.
 *
 * Built with the package's factory but saved by hand, because its
 * afterCreating hook creates a customer per subscription and
 * lemon_squeezy_customers is unique on (billable_id, billable_type) — a
 * second subscription for the same pilot, which several tests below need,
 * would collide on it.
 */
function entitlementSubscribe(
    User $user,
    string $variantId,
    string $status = Subscription::STATUS_ACTIVE,
    string $type = Subscription::DEFAULT_TYPE,
    ?DateTimeInterface $endsAt = null,
    ?string $pauseMode = null,
): Subscription {
    Customer::query()->firstOrCreate([
        'billable_id' => $user->id,
        'billable_type' => $user->getMorphClass(),
    ], [
        'lemon_squeezy_id' => (string) fake()->unique()->randomNumber(8),
    ]);

    $subscription = Subscription::factory()->make([
        'billable_id' => $user->id,
        'billable_type' => $user->getMorphClass(),
        'type' => $type,
        'lemon_squeezy_id' => (string) fake()->unique()->randomNumber(8),
        'status' => $status,
        'variant_id' => $variantId,
        'pause_mode' => $pauseMode,
        'ends_at' => $endsAt,
    ]);

    $subscription->save();

    return $subscription;
}
