<?php

declare(strict_types=1);

use App\Actions\ResolveFleetDefault;
use App\Enums\Feature;
use App\Enums\Plan;
use App\Models\Challenge;
use App\Models\ChallengeRun;
use App\Models\Course;
use App\Models\DroneModel;
use App\Models\User;
use App\Models\UserChallengeProgress;
use Illuminate\Support\Facades\Exceptions;
use Inertia\Testing\AssertableInertia;

/*
 * Choosing which drone to fly a mission in.
 *
 * The feature is sold on Pro, so half of what is asserted here is what a
 * Starter pilot cannot do — and specifically that they cannot do it by
 * skipping the page and posting at the endpoint, which is the only route that
 * would matter to anyone trying.
 *
 * The other half is that the choice reaches the two places it has to agree in:
 * the cockpit the pilot flies from, and the run the server grades. Those read
 * the same saved selection through App\Actions\ResolveMissionDrone, and a run
 * that recorded an airframe the pilot was not flying would poison the
 * analytics record it exists to keep honest.
 */

test('the drone configuration editor is a shipped pro capability', function (): void {
    expect(Feature::DroneConfigEditor->isAvailable())->toBeTrue();
    expect(Plan::Pro->hasFeature(Feature::DroneConfigEditor))->toBeTrue();
    expect(Plan::Starter->hasFeature(Feature::DroneConfigEditor))->toBeFalse();
});

test('the seeded fleet names exactly one default airframe', function (): void {
    expect(DroneModel::query()->fleetDefault()->count())->toBe(1);
    expect(DroneModel::query()->count())->toBeGreaterThan(1);
});

test('a fleet naming no default airframe refuses to resolve one', function (): void {
    DroneModel::query()->fleetDefault()->update(['is_default' => false]);

    $this->expectException(RuntimeException::class);

    resolve(ResolveFleetDefault::class)->handle();
});

/**
 * The dangerous half of the invariant.
 *
 * A fleet with no default fails on its own — there is nothing to return.
 * A fleet with two returns a drone perfectly happily, and it is whichever
 * one the database put first, so the simulator would keep working while
 * flying pilots in an airframe nobody chose on missions balanced around
 * the Surveyor. Reordering the catalogue would then quietly rebalance it.
 */
test('a fleet naming two default airframes refuses to pick between them', function (): void {
    DroneModel::factory()->default()->create();

    $this->expectException(RuntimeException::class);

    resolve(ResolveFleetDefault::class)->handle();
});

test('a starter pilot flies the default airframe and is not offered the fleet', function (): void {
    $user = User::factory()->create();
    [$course, $challenge] = mission();

    $response = $this->actingAs($user)->get(route('challenges.show', [$course, $challenge]));

    $response->assertOk();
    $response->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
        ->where('drone.slug', defaultDrone()->slug)
        // Null rather than absent: the cockpit renders the upgrade prompt
        // where the picker would be, and it needs to be told to.
        ->where('fleet', null));
});

test('a pro pilot is offered the whole fleet', function (): void {
    $user = User::factory()->onPlan(Plan::Pro)->create();
    [$course, $challenge] = mission();

    $response = $this->actingAs($user)->get(route('challenges.show', [$course, $challenge]));

    $response->assertOk();
    $response->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
        ->where('drone.slug', defaultDrone()->slug)
        ->has('fleet', DroneModel::query()->count())
        // The specs travel with the fleet: the browser is what flies the
        // drone, and it has no second source for these numbers.
        ->has('fleet.0.flight.cruiseSpeed')
        ->has('fleet.0.airframe.rotors'));
});

test('a pro pilot can choose an airframe and the cockpit flies it', function (): void {
    $user = User::factory()->onPlan(Plan::Pro)->create();
    [$course, $challenge] = mission();
    $vector = droneNamed('vx-4-vector');

    $this->actingAs($user)
        ->from(route('challenges.show', [$course, $challenge]))
        ->put(route('challenges.drone.update', [$course, $challenge]), ['drone' => $vector->uuid])
        ->assertRedirect(route('challenges.show', [$course, $challenge]));

    $this->assertDatabaseHas('user_challenge_progress', [
        'user_id' => $user->id,
        'challenge_id' => $challenge->id,
        'drone_model_id' => $vector->id,
    ]);

    $this->actingAs($user)
        ->get(route('challenges.show', [$course, $challenge]))
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->where('drone.slug', 'vx-4-vector')
            ->where('drone.flight.cruiseSpeed', $vector->flight_spec['cruiseSpeed']));
});

test('choosing an airframe before flying does not count as having started', function (): void {
    $user = User::factory()->onPlan(Plan::Pro)->create();
    [$course, $challenge] = mission();

    $this->actingAs($user)->put(
        route('challenges.drone.update', [$course, $challenge]),
        ['drone' => droneNamed('tr-4-cadet')->uuid],
    );

    $progress = UserChallengeProgress::query()
        ->where('user_id', $user->id)
        ->where('challenge_id', $challenge->id)
        ->sole();

    expect($progress->attempts)->toBe(0);
    expect($progress->best_score)->toBe(0);
    expect($progress->completed_at)->toBeNull();
});

test('the choice is remembered per mission not across them', function (): void {
    $user = User::factory()->onPlan(Plan::Pro)->create();
    $course = Course::factory()->create();
    $slalom = Challenge::factory()->for($course)->create();
    $survey = Challenge::factory()->for($course)->create();

    $this->actingAs($user)->put(
        route('challenges.drone.update', [$course, $slalom]),
        ['drone' => droneNamed('vx-4-vector')->uuid],
    );

    $this->actingAs($user)
        ->get(route('challenges.show', [$course, $slalom]))
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page->where('drone.slug', 'vx-4-vector'));

    // The other mission is untouched: an airframe chosen for a slalom is
    // not a standing preference, it is a decision about that mission.
    $this->actingAs($user)
        ->get(route('challenges.show', [$course, $survey]))
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page->where('drone.slug', defaultDrone()->slug));
});

test('a starter pilot cannot choose an airframe even by posting directly', function (): void {
    $user = User::factory()->create();
    [$course, $challenge] = mission();

    $this->actingAs($user)
        ->put(route('challenges.drone.update', [$course, $challenge]), [
            'drone' => droneNamed('vx-4-vector')->uuid,
        ])
        ->assertForbidden();

    $this->assertDatabaseMissing('user_challenge_progress', [
        'user_id' => $user->id,
        'challenge_id' => $challenge->id,
    ]);
});

test('a lapsed pilot stops flying the airframe their plan bought', function (): void {
    $user = User::factory()->onPlan(Plan::Pro)->create();
    [$course, $challenge] = mission();

    $this->actingAs($user)->put(
        route('challenges.drone.update', [$course, $challenge]),
        ['drone' => droneNamed('vx-4-vector')->uuid],
    );

    // The selection row survives the downgrade; the entitlement does not.
    // A subscription that keeps flying the racing quad after it lapses is
    // a paid capability that never actually ends.
    //
    // Assigned rather than mass-assigned: `plan_override` is deliberately
    // not in User's Fillable list — it is an entitlement, not something a
    // request may set — so `update()` would drop it silently and this test
    // would pass by never having downgraded anyone.
    //
    // `forgetPlan()` because `actingAs` keeps this very instance as the
    // authenticated user across the request below, and HasPlan memoises
    // resolution on the instance. In production the downgrade and the next
    // request are separate requests with separately hydrated users, so the
    // memo is already gone; here it has to be dropped by hand.
    $user->plan_override = Plan::Starter->value;
    $user->save();
    $user->forgetPlan();

    $this->actingAs($user)
        ->get(route('challenges.show', [$course, $challenge]))
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->where('drone.slug', defaultDrone()->slug)
            ->where('fleet', null));
});

test('a guest is sent to login rather than choosing an airframe', function (): void {
    [$course, $challenge] = mission();

    $this->put(route('challenges.drone.update', [$course, $challenge]), [
        'drone' => droneNamed('vx-4-vector')->uuid,
    ])->assertRedirect(route('login'));
});

test('an unknown airframe is rejected', function (): void {
    $user = User::factory()->onPlan(Plan::Pro)->create();
    [$course, $challenge] = mission();

    $this->actingAs($user)
        ->from(route('challenges.show', [$course, $challenge]))
        ->put(route('challenges.drone.update', [$course, $challenge]), [
            'drone' => '00000000-0000-0000-0000-000000000000',
        ])
        ->assertSessionHasErrors('drone');
});

test('a mission the pilot cannot fly cannot be configured', function (): void {
    // Pro reaches the feature but not this mission, which is a Team
    // course. The two gates are separate questions and both have to hold.
    $user = User::factory()->onPlan(Plan::Pro)->create();
    $course = Course::factory()->requiring(Plan::Team)->create();
    $challenge = Challenge::factory()->for($course)->create();

    $this->actingAs($user)
        ->put(route('challenges.drone.update', [$course, $challenge]), [
            'drone' => droneNamed('vx-4-vector')->uuid,
        ])
        ->assertForbidden();
});

test('a graded run records the airframe that flew it', function (): void {
    $user = User::factory()->onPlan(Plan::Pro)->create();
    [$course, $challenge] = mission();
    $cadet = droneNamed('tr-4-cadet');

    $this->actingAs($user)->put(
        route('challenges.drone.update', [$course, $challenge]),
        ['drone' => $cadet->uuid],
    );

    $this->actingAs($user)->postJson(
        route('challenges.attempts.store', [$course, $challenge]),
        droneConfigFlight(),
    )->assertOk();

    expect(ChallengeRun::query()->where('user_id', $user->id)->sole()->drone_model_id)->toBe($cadet->id);
});

test('a run flown without choosing is attributed to the default', function (): void {
    $user = User::factory()->create();
    [$course, $challenge] = mission();

    $this->actingAs($user)->postJson(
        route('challenges.attempts.store', [$course, $challenge]),
        droneConfigFlight(),
    )->assertOk();

    expect(ChallengeRun::query()->where('user_id', $user->id)->sole()->drone_model_id)->toBe(defaultDrone()->id);
});

test('a submission cannot name the airframe it was flown in', function (): void {
    // The whole reason the attribution is trustworthy: a Starter pilot
    // posting a Vector's uuid with their run is recorded flying the
    // default, because the server reads its own saved selection and never
    // the payload.
    $user = User::factory()->create();
    [$course, $challenge] = mission();

    $this->actingAs($user)->postJson(
        route('challenges.attempts.store', [$course, $challenge]),
        droneConfigFlight(['drone' => droneNamed('vx-4-vector')->uuid]),
    )->assertOk();

    expect(ChallengeRun::query()->where('user_id', $user->id)->sole()->drone_model_id)->toBe(defaultDrone()->id);
});

test('retiring an airframe keeps the runs it flew', function (): void {
    $user = User::factory()->onPlan(Plan::Pro)->create();
    [$course, $challenge] = mission();
    $cadet = droneNamed('tr-4-cadet');

    $this->actingAs($user)->put(
        route('challenges.drone.update', [$course, $challenge]),
        ['drone' => $cadet->uuid],
    );
    $this->actingAs($user)->postJson(
        route('challenges.attempts.store', [$course, $challenge]),
        droneConfigFlight(),
    )->assertOk();

    $cadet->delete();

    // The run is a fact about a flight that happened; the analytics record
    // must not lose it because the fleet changed. Same for the pilot's
    // progress, which falls back to the default airframe.
    expect(ChallengeRun::query()->where('user_id', $user->id)->count())->toBe(1);
    expect(ChallengeRun::query()->where('user_id', $user->id)->sole()->drone_model_id)->toBeNull();

    $this->actingAs($user)
        ->get(route('challenges.show', [$course, $challenge]))
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page->where('drone.slug', defaultDrone()->slug));
});

test('the landing page advertises the fleets actual default airframe', function (): void {
    $this->get(route('home'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->where('drone.slug', defaultDrone()->slug)
            ->has('drone.airframe.rotors'));
});

/**
 * The one caller of the fleet default that survives it being unresolvable.
 *
 * A cockpit with no airframe has nothing to fly, so the mission page is
 * right to fail. The hero drone is decoration on the page that explains
 * the product and takes the signup, and taking the storefront down over an
 * ornament costs signups to fix nothing. Still reported: the deploy is
 * broken, it is just not the visitor's problem.
 */
test('the landing page survives a fleet with no resolvable default', function (): void {
    Exceptions::fake();
    DroneModel::query()->fleetDefault()->update(['is_default' => false]);

    $this->get(route('home'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->where('drone', null)
            // The rest of the pitch is untouched — the drone is the only
            // thing the broken fleet costs the page.
            ->has('courses')
            ->has('missionCount'));

    Exceptions::assertReported(RuntimeException::class);
});

/**
 * A published mission on a published course, open to every plan.
 *
 * @return array{0: Course, 1: Challenge}
 */
function mission(): array
{
    $course = Course::factory()->create();

    return [$course, Challenge::factory()->for($course)->create()];
}

function defaultDrone(): DroneModel
{
    return DroneModel::query()->fleetDefault()->sole();
}

function droneNamed(string $slug): DroneModel
{
    return DroneModel::query()->where('slug', $slug)->sole();
}

/**
 * A run that takes off and lands, which clears an unconstrained mission.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function droneConfigFlight(array $overrides = []): array
{
    return array_merge([
        'code' => 'async function main(drone) { await drone.takeoff(); await drone.land(); }',
        'collisions' => 0,
        'path' => [
            ['t' => 0, 'x' => 0, 'y' => 0.15, 'z' => 0],
            ['t' => 1, 'x' => 0, 'y' => 2, 'z' => 0],
            ['t' => 2, 'x' => 0, 'y' => 0.15, 'z' => 0],
        ],
        'photos' => [],
    ], $overrides);
}
