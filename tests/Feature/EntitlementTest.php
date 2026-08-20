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

    $this->assertSame(Plan::Starter, resolvePlanFor($user));
});

test('a guest resolves to starter', function (): void {
    $this->assertSame(Plan::Starter, resolve(ResolvePlanForUser::class)->handle(null));
});

test('a plan override resolves to that plan', function (): void {
    $user = User::factory()->onPlan(Plan::Pro)->create();

    $this->assertSame(Plan::Pro, resolvePlanFor($user));
});

test('a plan override outranks an active subscription', function (): void {
    $user = User::factory()->onPlan(Plan::Enterprise)->create();
    entitlementSubscribe($user, 'var_pro_monthly');

    $this->assertSame(Plan::Enterprise, resolvePlanFor($user));
});

test('an override naming a retired plan falls through instead of throwing', function (): void {
    $user = User::factory()->create(['plan_override' => 'platinum']);

    $this->assertSame(Plan::Starter, resolvePlanFor($user));
});

/**
 * A Lemon Squeezy subscription names the variant it sells on the row itself,
 * so the plan is read straight off `variant_id`. Paddle's subscription_items
 * join has no counterpart in this schema, and nothing looks for one.
 */
test('an active subscription resolves through the variant on its own row', function (): void {
    $user = User::factory()->create();
    $subscription = entitlementSubscribe($user, 'var_pro_monthly');

    $this->assertSame('var_pro_monthly', $subscription->variant_id);
    $this->assertSame(Plan::Pro, resolvePlanFor($user));
});

test('a trialing subscription still grants its plan', function (): void {
    $user = User::factory()->create();
    entitlementSubscribe($user, 'var_pro_yearly', Subscription::STATUS_ON_TRIAL);

    $this->assertSame(Plan::Pro, resolvePlanFor($user));
});

/**
 * A card that bounced is a payment problem, not a decision to leave. Lemon
 * Squeezy retries for days before it gives up, and locking the catalogue on
 * the first failure would punish an expired card harder than a cancellation.
 */
test('a past due subscription still grants its plan', function (): void {
    $user = User::factory()->create();
    entitlementSubscribe($user, 'var_pro_monthly', Subscription::STATUS_PAST_DUE);

    $this->assertSame(Plan::Pro, resolvePlanFor($user));
});

/**
 * They bought the month, so they keep it. `valid()` stays true through the
 * grace period after a cancellation, which is what makes CancelSubscription's
 * end-of-period promise hold without a special case anywhere.
 */
test('a cancelled subscription grants its plan until the paid period runs out', function (): void {
    $user = User::factory()->create();
    entitlementSubscribe($user, 'var_pro_monthly', Subscription::STATUS_CANCELLED, endsAt: now()->addWeek());

    $this->assertSame(Plan::Pro, resolvePlanFor($user));
});

test('a cancelled subscription grants nothing once the period has run out', function (): void {
    $user = User::factory()->create();
    entitlementSubscribe($user, 'var_pro_monthly', Subscription::STATUS_CANCELLED, endsAt: now()->subDay());

    $this->assertSame(Plan::Starter, resolvePlanFor($user));
});

test('an expired subscription grants nothing', function (): void {
    $user = User::factory()->create();
    entitlementSubscribe($user, 'var_pro_monthly', Subscription::STATUS_EXPIRED);

    $this->assertSame(Plan::Starter, resolvePlanFor($user));
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

    $this->assertSame(Plan::Pro, resolvePlanFor($free));
    $this->assertSame(Plan::Starter, resolvePlanFor($void));
});

test('the most generous of several subscriptions wins', function (): void {
    $user = User::factory()->create();
    entitlementSubscribe($user, 'var_pro_monthly');
    entitlementSubscribe($user, 'var_team_monthly', type: 'classroom');

    $this->assertSame(Plan::Team, resolvePlanFor($user));
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

    $this->assertSame(Plan::Team, resolvePlanFor($user));
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

    $this->assertSame(Plan::Pro, resolvePlanFor($user));
});

test('a subscription to an unrecognised variant grants nothing', function (): void {
    $user = User::factory()->create();
    entitlementSubscribe($user, 'var_some_retired_experiment');

    $this->assertSame(Plan::Starter, resolvePlanFor($user));
});

test('one users subscription does not leak to another', function (): void {
    $subscriber = User::factory()->create();
    entitlementSubscribe($subscriber, 'var_pro_monthly');

    $bystander = User::factory()->create();

    $this->assertSame(Plan::Pro, resolvePlanFor($subscriber));
    $this->assertSame(Plan::Starter, resolvePlanFor($bystander));
});

test('the model answers feature questions from its plan', function (): void {
    $starter = User::factory()->create();
    $pro = User::factory()->onPlan(Plan::Pro)->create();

    $this->assertFalse($starter->hasFeature(Feature::PythonRuntime));
    $this->assertFalse($starter->onPaidPlan());
    $this->assertSame([], $starter->features());

    $this->assertTrue($pro->hasFeature(Feature::PythonRuntime));
    $this->assertTrue($pro->onPaidPlan());
    $this->assertTrue($pro->planCovers(Plan::Starter));
});

test('the resolved plan is memoised until forgotten', function (): void {
    $user = User::factory()->create();

    $this->assertSame(Plan::Starter, $user->plan());

    $user->plan_override = Plan::Pro->value;
    $user->save();

    $this->assertSame(Plan::Starter, $user->plan(), 'The memo should survive a change made after resolution.');
    $this->assertSame(Plan::Pro, $user->forgetPlan()->plan());
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
    $enterprise = User::factory()->onPlan(Plan::Enterprise)->create();
    $starter = User::factory()->create();

    foreach (Feature::cases() as $feature) {
        $this->assertTrue($enterprise->can($feature->value), $feature->value);
        $this->assertFalse($starter->can($feature->value), $feature->value);
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
    $user = User::factory()->onPlan(Plan::Enterprise)->create();

    $this->assertArrayNotHasKey('plan_override', $user->toArray());
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
    $comped = User::factory()->onPlan(Plan::Enterprise)->create();

    $migration = require database_path('migrations/2026_07_28_092306_backfill_pre_launch_users_to_pro.php');
    $migration->up();

    $this->assertSame(Plan::Pro, resolvePlanFor($preLaunch));
    $this->assertSame(Plan::Enterprise, resolvePlanFor($comped));
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
