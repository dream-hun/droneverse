<?php

declare(strict_types=1);

use App\Actions\ResolveFeaturesForUser;
use App\Actions\ResolvePlanForUser;
use App\Enums\Feature;
use App\Enums\Plan;
use App\Models\Challenge;
use App\Models\Course;
use App\Models\Quiz;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia;

/** Everything the Pro plan in kelviq.config.ts grants. */
const KELVIQ_PRO = ['full-catalog', 'drone-config-editor', 'advanced-analytics', 'priority-support', 'beta-access'];

test('a user with nothing resolves to starter', function (): void {
    $user = User::factory()->create();

    expect(resolvePlanFor($user))->toBe(Plan::Starter);
});

test('a guest resolves to starter and holds nothing', function (): void {
    expect(resolve(ResolvePlanForUser::class)->handle(null))->toBe(Plan::Starter)
        ->and(resolve(ResolveFeaturesForUser::class)->handle(null))->toBe([]);
});

test('a plan override resolves to that plan', function (): void {
    $user = User::factory()->onPlan(Plan::Pro)->create();

    expect(resolvePlanFor($user))->toBe(Plan::Pro);
});

test('a kelviq subscriber holding the full catalogue is on pro', function (): void {
    $user = User::factory()->create();
    fakeKelviq([$user->uuid => KELVIQ_PRO]);

    expect(resolvePlanFor($user))->toBe(Plan::Pro);
});

test('a kelviq customer without the full catalogue is on starter', function (): void {
    $user = User::factory()->create();
    fakeKelviq([$user->uuid => ['advanced-analytics']]);

    expect(resolvePlanFor($user))->toBe(Plan::Starter);
});

/**
 * Staff and comped accounts are not Kelviq customers, and asking Kelviq about
 * them would answer "nothing". The override says something billing does not
 * know, so it is not asked.
 */
test('a plan override outranks kelviq in both directions', function (): void {
    $comped = User::factory()->onPlan(Plan::Pro)->create();
    $demoted = User::factory()->onPlan(Plan::Starter)->create();
    fakeKelviq([$demoted->uuid => KELVIQ_PRO]);

    expect(resolvePlanFor($comped))->toBe(Plan::Pro)
        ->and(resolvePlanFor($demoted))->toBe(Plan::Starter)
        ->and($demoted->fresh()?->features())->toBe([]);
});

test('an override naming a retired plan falls through instead of throwing', function (): void {
    $user = User::factory()->create(['plan_override' => 'team']);

    expect(resolvePlanFor($user))->toBe(Plan::Starter);
});

test('one users entitlements do not leak to another', function (): void {
    $subscriber = User::factory()->create();
    $bystander = User::factory()->create();
    fakeKelviq([$subscriber->uuid => KELVIQ_PRO]);

    expect(resolvePlanFor($subscriber))->toBe(Plan::Pro)
        ->and(resolvePlanFor($bystander))->toBe(Plan::Starter);
});

/**
 * A plan reshaped in Kelviq changes what a subscriber has without a deploy
 * here, so each feature is its own entitlement rather than read off the plan.
 */
test('a kelviq subscriber holds exactly the features kelviq grants', function (): void {
    $user = User::factory()->create();
    fakeKelviq([$user->uuid => ['full-catalog', 'advanced-analytics']]);

    expect($user->features())->toBe([Feature::AdvancedAnalytics])
        ->and($user->hasFeature(Feature::AdvancedAnalytics))->toBeTrue()
        ->and($user->hasFeature(Feature::DroneConfigEditor))->toBeFalse();
});

/**
 * An unbuilt capability has no Kelviq feature, so nobody paying through Kelviq
 * holds it — only an override granting the whole of Pro does.
 */
test('an override grants what its plan lists, unbuilt capabilities included', function (): void {
    $comped = User::factory()->onPlan(Plan::Pro)->create();
    $paying = User::factory()->create();
    fakeKelviq([$paying->uuid => KELVIQ_PRO]);

    expect($comped->hasFeature(Feature::MissionBuilder))->toBeTrue()
        ->and($paying->hasFeature(Feature::MissionBuilder))->toBeFalse()
        ->and($paying->hasFeature(Feature::DroneConfigEditor))->toBeTrue();
});

test('the model answers feature questions from its plan', function (): void {
    $starter = User::factory()->create();
    $pro = User::factory()->onPlan(Plan::Pro)->create();

    expect($starter->hasFeature(Feature::MissionBuilder))->toBeFalse()
        ->and($starter->onPaidPlan())->toBeFalse()
        ->and($starter->features())->toBe([])
        ->and($pro->hasFeature(Feature::MissionBuilder))->toBeTrue()
        ->and($pro->onPaidPlan())->toBeTrue()
        ->and($pro->planCovers(Plan::Starter))->toBeTrue();
});

test('the resolved plan and features are memoised until forgotten', function (): void {
    $user = User::factory()->create();

    expect($user->plan())->toBe(Plan::Starter)
        ->and($user->features())->toBe([]);

    $user->plan_override = Plan::Pro->value;
    $user->save();

    expect($user->plan())->toBe(Plan::Starter, 'The memo should survive a change made after resolution.')
        ->and($user->features())->toBe([])
        ->and($user->forgetPlan()->plan())->toBe(Plan::Pro)
        ->and($user->features())->toBe(Plan::Pro->features());
});

test('a gated route rejects starter and admits pro', function (): void {
    Route::middleware(['web', 'auth', 'can:'.Feature::AdvancedAnalytics->value])
        ->get('__test__/analytics', fn (): string => 'ok');

    $starter = User::factory()->create();
    $pro = User::factory()->onPlan(Plan::Pro)->create();
    $paying = User::factory()->create();
    fakeKelviq([$paying->uuid => KELVIQ_PRO]);

    $this->actingAs($starter)->get('__test__/analytics')->assertForbidden();
    $this->actingAs($pro)->get('__test__/analytics')->assertOk();
    $this->actingAs($paying)->get('__test__/analytics')->assertOk();
});

test('every feature is registered as a gate', function (): void {
    $pro = User::factory()->onPlan(Plan::Pro)->create();
    $starter = User::factory()->create();

    foreach (Feature::cases() as $feature) {
        expect($pro->can($feature->value))->toBeTrue($feature->value)
            ->and($starter->can($feature->value))->toBeFalse($feature->value);
    }
});

/**
 * An entitlement check that errors with nothing cached to fall back on fails
 * closed: an outage for a subscriber, never free Pro for anybody.
 */
test('a pilot kelviq cannot answer for is on starter', function (): void {
    $user = User::factory()->create();
    fakeKelviq(responses: ['edge.sandboxapi.kelviq.com/*' => Http::response('down', 503)]);

    expect(resolvePlanFor($user))->toBe(Plan::Starter)
        ->and($user->fresh()?->features())->toBe([]);
});

test('entitlements are shared with the front end', function (): void {
    $user = User::factory()->create();
    fakeKelviq([$user->uuid => KELVIQ_PRO]);

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->where('auth.plan.value', 'pro')
            ->where('auth.plan.label', 'Pro')
            ->where('auth.plan.isPaid', true)
            ->where('auth.features', fn (Collection $features): bool => $features->contains('drone_config_editor')
                && $features->doesntContain('mission_builder')));
});

test('guests are shared the starter plan', function (): void {
    $this->get(route('home'))
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->where('auth.plan.value', 'starter')
            ->where('auth.features', []));
});

test('the plan override is never serialised to the client', function (): void {
    $user = User::factory()->onPlan(Plan::Pro)->create();

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
    $comped = User::factory()->onPlan(Plan::Starter)->create();

    $migration = require database_path('migrations/2026_07_28_092306_backfill_pre_launch_users_to_pro.php');

    $this->assertInstanceOf(Migration::class, $migration);
    $this->assertTrue(method_exists($migration, 'up'));

    $migration->up();

    expect(resolvePlanFor($preLaunch))->toBe(Plan::Pro)
        ->and(resolvePlanFor($comped))->toBe(Plan::Starter);
});

/**
 * Team is retired, and a stored `team` left in place would read as Starter:
 * the content it gated would open to everybody and the pilots comped onto it
 * would lose their upgrade. Both move to Pro.
 */
test('the team tier is retired onto pro', function (): void {
    $comped = User::factory()->create(['plan_override' => 'team']);
    $course = Course::factory()->create(['required_plan' => 'team']);
    $challenge = Challenge::factory()->for($course)->create(['required_plan' => 'team']);
    $quiz = Quiz::factory()->for($course)->create(['required_plan' => 'team']);
    $free = Course::factory()->create(['required_plan' => 'starter']);

    $migration = require database_path('migrations/2026_09_27_100001_move_team_plan_to_pro.php');

    $this->assertInstanceOf(Migration::class, $migration);
    $this->assertTrue(method_exists($migration, 'up'));

    $migration->up();

    expect($comped->fresh()?->plan_override)->toBe('pro')
        ->and($course->fresh()?->required_plan)->toBe('pro')
        ->and($challenge->fresh()?->required_plan)->toBe('pro')
        ->and($quiz->fresh()?->required_plan)->toBe('pro')
        ->and($free->fresh()?->required_plan)->toBe('starter');
});

function resolvePlanFor(User $user): Plan
{
    return resolve(ResolvePlanForUser::class)->handle($user->fresh());
}
