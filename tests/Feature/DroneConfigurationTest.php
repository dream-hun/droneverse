<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Actions\ResolveFleetDefault;
use App\Enums\Feature;
use App\Enums\Plan;
use App\Models\Challenge;
use App\Models\ChallengeRun;
use App\Models\Course;
use App\Models\DroneModel;
use App\Models\User;
use App\Models\UserChallengeProgress;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Exceptions;
use RuntimeException;
use Tests\TestCase;

/**
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
final class DroneConfigurationTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_drone_configuration_editor_is_a_shipped_pro_capability(): void
    {
        $this->assertTrue(Feature::DroneConfigEditor->isAvailable());
        $this->assertTrue(Plan::Pro->hasFeature(Feature::DroneConfigEditor));
        $this->assertFalse(Plan::Starter->hasFeature(Feature::DroneConfigEditor));
    }

    public function test_the_seeded_fleet_names_exactly_one_default_airframe(): void
    {
        $this->assertSame(1, DroneModel::query()->fleetDefault()->count());
        $this->assertGreaterThan(1, DroneModel::query()->count());
    }

    public function test_a_fleet_naming_no_default_airframe_refuses_to_resolve_one(): void
    {
        DroneModel::query()->fleetDefault()->update(['is_default' => false]);

        $this->expectException(RuntimeException::class);

        resolve(ResolveFleetDefault::class)->handle();
    }

    /**
     * The dangerous half of the invariant.
     *
     * A fleet with no default fails on its own — there is nothing to return.
     * A fleet with two returns a drone perfectly happily, and it is whichever
     * one the database put first, so the simulator would keep working while
     * flying pilots in an airframe nobody chose on missions balanced around
     * the Surveyor. Reordering the catalogue would then quietly rebalance it.
     */
    public function test_a_fleet_naming_two_default_airframes_refuses_to_pick_between_them(): void
    {
        DroneModel::factory()->default()->create();

        $this->expectException(RuntimeException::class);

        resolve(ResolveFleetDefault::class)->handle();
    }

    public function test_a_starter_pilot_flies_the_default_airframe_and_is_not_offered_the_fleet(): void
    {
        $user = User::factory()->create();
        [$course, $challenge] = $this->mission();

        $response = $this->actingAs($user)->get(route('challenges.show', [$course, $challenge]));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->where('drone.slug', $this->defaultDrone()->slug)
            // Null rather than absent: the cockpit renders the upgrade prompt
            // where the picker would be, and it needs to be told to.
            ->where('fleet', null));
    }

    public function test_a_pro_pilot_is_offered_the_whole_fleet(): void
    {
        $user = User::factory()->onPlan(Plan::Pro)->create();
        [$course, $challenge] = $this->mission();

        $response = $this->actingAs($user)->get(route('challenges.show', [$course, $challenge]));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->where('drone.slug', $this->defaultDrone()->slug)
            ->has('fleet', DroneModel::query()->count())
            // The specs travel with the fleet: the browser is what flies the
            // drone, and it has no second source for these numbers.
            ->has('fleet.0.flight.cruiseSpeed')
            ->has('fleet.0.airframe.rotors'));
    }

    public function test_a_pro_pilot_can_choose_an_airframe_and_the_cockpit_flies_it(): void
    {
        $user = User::factory()->onPlan(Plan::Pro)->create();
        [$course, $challenge] = $this->mission();
        $vector = $this->droneNamed('vx-4-vector');

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
            ->assertInertia(fn ($page) => $page
                ->where('drone.slug', 'vx-4-vector')
                ->where('drone.flight.cruiseSpeed', $vector->flight_spec['cruiseSpeed']));
    }

    public function test_choosing_an_airframe_before_flying_does_not_count_as_having_started(): void
    {
        $user = User::factory()->onPlan(Plan::Pro)->create();
        [$course, $challenge] = $this->mission();

        $this->actingAs($user)->put(
            route('challenges.drone.update', [$course, $challenge]),
            ['drone' => $this->droneNamed('tr-4-cadet')->uuid],
        );

        $progress = UserChallengeProgress::query()
            ->where('user_id', $user->id)
            ->where('challenge_id', $challenge->id)
            ->sole();

        $this->assertSame(0, $progress->attempts);
        $this->assertSame(0, $progress->best_score);
        $this->assertNull($progress->completed_at);
    }

    public function test_the_choice_is_remembered_per_mission_not_across_them(): void
    {
        $user = User::factory()->onPlan(Plan::Pro)->create();
        $course = Course::factory()->create();
        $slalom = Challenge::factory()->for($course)->create();
        $survey = Challenge::factory()->for($course)->create();

        $this->actingAs($user)->put(
            route('challenges.drone.update', [$course, $slalom]),
            ['drone' => $this->droneNamed('vx-4-vector')->uuid],
        );

        $this->actingAs($user)
            ->get(route('challenges.show', [$course, $slalom]))
            ->assertInertia(fn ($page) => $page->where('drone.slug', 'vx-4-vector'));

        // The other mission is untouched: an airframe chosen for a slalom is
        // not a standing preference, it is a decision about that mission.
        $this->actingAs($user)
            ->get(route('challenges.show', [$course, $survey]))
            ->assertInertia(fn ($page) => $page->where('drone.slug', $this->defaultDrone()->slug));
    }

    public function test_a_starter_pilot_cannot_choose_an_airframe_even_by_posting_directly(): void
    {
        $user = User::factory()->create();
        [$course, $challenge] = $this->mission();

        $this->actingAs($user)
            ->put(route('challenges.drone.update', [$course, $challenge]), [
                'drone' => $this->droneNamed('vx-4-vector')->uuid,
            ])
            ->assertForbidden();

        $this->assertDatabaseMissing('user_challenge_progress', [
            'user_id' => $user->id,
            'challenge_id' => $challenge->id,
        ]);
    }

    public function test_a_lapsed_pilot_stops_flying_the_airframe_their_plan_bought(): void
    {
        $user = User::factory()->onPlan(Plan::Pro)->create();
        [$course, $challenge] = $this->mission();

        $this->actingAs($user)->put(
            route('challenges.drone.update', [$course, $challenge]),
            ['drone' => $this->droneNamed('vx-4-vector')->uuid],
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
            ->assertInertia(fn ($page) => $page
                ->where('drone.slug', $this->defaultDrone()->slug)
                ->where('fleet', null));
    }

    public function test_a_guest_is_sent_to_login_rather_than_choosing_an_airframe(): void
    {
        [$course, $challenge] = $this->mission();

        $this->put(route('challenges.drone.update', [$course, $challenge]), [
            'drone' => $this->droneNamed('vx-4-vector')->uuid,
        ])->assertRedirect(route('login'));
    }

    public function test_an_unknown_airframe_is_rejected(): void
    {
        $user = User::factory()->onPlan(Plan::Pro)->create();
        [$course, $challenge] = $this->mission();

        $this->actingAs($user)
            ->from(route('challenges.show', [$course, $challenge]))
            ->put(route('challenges.drone.update', [$course, $challenge]), [
                'drone' => '00000000-0000-0000-0000-000000000000',
            ])
            ->assertSessionHasErrors('drone');
    }

    public function test_a_mission_the_pilot_cannot_fly_cannot_be_configured(): void
    {
        // Pro reaches the feature but not this mission, which is a Team
        // course. The two gates are separate questions and both have to hold.
        $user = User::factory()->onPlan(Plan::Pro)->create();
        $course = Course::factory()->requiring(Plan::Team)->create();
        $challenge = Challenge::factory()->for($course)->create();

        $this->actingAs($user)
            ->put(route('challenges.drone.update', [$course, $challenge]), [
                'drone' => $this->droneNamed('vx-4-vector')->uuid,
            ])
            ->assertForbidden();
    }

    public function test_a_graded_run_records_the_airframe_that_flew_it(): void
    {
        $user = User::factory()->onPlan(Plan::Pro)->create();
        [$course, $challenge] = $this->mission();
        $cadet = $this->droneNamed('tr-4-cadet');

        $this->actingAs($user)->put(
            route('challenges.drone.update', [$course, $challenge]),
            ['drone' => $cadet->uuid],
        );

        $this->actingAs($user)->postJson(
            route('challenges.attempts.store', [$course, $challenge]),
            $this->flight(),
        )->assertOk();

        $this->assertSame(
            $cadet->id,
            ChallengeRun::query()->where('user_id', $user->id)->sole()->drone_model_id,
        );
    }

    public function test_a_run_flown_without_choosing_is_attributed_to_the_default(): void
    {
        $user = User::factory()->create();
        [$course, $challenge] = $this->mission();

        $this->actingAs($user)->postJson(
            route('challenges.attempts.store', [$course, $challenge]),
            $this->flight(),
        )->assertOk();

        $this->assertSame(
            $this->defaultDrone()->id,
            ChallengeRun::query()->where('user_id', $user->id)->sole()->drone_model_id,
        );
    }

    public function test_a_submission_cannot_name_the_airframe_it_was_flown_in(): void
    {
        // The whole reason the attribution is trustworthy: a Starter pilot
        // posting a Vector's uuid with their run is recorded flying the
        // default, because the server reads its own saved selection and never
        // the payload.
        $user = User::factory()->create();
        [$course, $challenge] = $this->mission();

        $this->actingAs($user)->postJson(
            route('challenges.attempts.store', [$course, $challenge]),
            $this->flight(['drone' => $this->droneNamed('vx-4-vector')->uuid]),
        )->assertOk();

        $this->assertSame(
            $this->defaultDrone()->id,
            ChallengeRun::query()->where('user_id', $user->id)->sole()->drone_model_id,
        );
    }

    public function test_retiring_an_airframe_keeps_the_runs_it_flew(): void
    {
        $user = User::factory()->onPlan(Plan::Pro)->create();
        [$course, $challenge] = $this->mission();
        $cadet = $this->droneNamed('tr-4-cadet');

        $this->actingAs($user)->put(
            route('challenges.drone.update', [$course, $challenge]),
            ['drone' => $cadet->uuid],
        );
        $this->actingAs($user)->postJson(
            route('challenges.attempts.store', [$course, $challenge]),
            $this->flight(),
        )->assertOk();

        $cadet->delete();

        // The run is a fact about a flight that happened; the analytics record
        // must not lose it because the fleet changed. Same for the pilot's
        // progress, which falls back to the default airframe.
        $this->assertSame(1, ChallengeRun::query()->where('user_id', $user->id)->count());
        $this->assertNull(ChallengeRun::query()->where('user_id', $user->id)->sole()->drone_model_id);

        $this->actingAs($user)
            ->get(route('challenges.show', [$course, $challenge]))
            ->assertInertia(fn ($page) => $page->where('drone.slug', $this->defaultDrone()->slug));
    }

    public function test_the_landing_page_advertises_the_fleets_actual_default_airframe(): void
    {
        $this->get(route('home'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('drone.slug', $this->defaultDrone()->slug)
                ->has('drone.airframe.rotors'));
    }

    /**
     * The one caller of the fleet default that survives it being unresolvable.
     *
     * A cockpit with no airframe has nothing to fly, so the mission page is
     * right to fail. The hero drone is decoration on the page that explains
     * the product and takes the signup, and taking the storefront down over an
     * ornament costs signups to fix nothing. Still reported: the deploy is
     * broken, it is just not the visitor's problem.
     */
    public function test_the_landing_page_survives_a_fleet_with_no_resolvable_default(): void
    {
        Exceptions::fake();
        DroneModel::query()->fleetDefault()->update(['is_default' => false]);

        $this->get(route('home'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('drone', null)
                // The rest of the pitch is untouched — the drone is the only
                // thing the broken fleet costs the page.
                ->has('courses')
                ->has('missionCount'));

        Exceptions::assertReported(RuntimeException::class);
    }

    /**
     * A published mission on a published course, open to every plan.
     *
     * @return array{0: Course, 1: Challenge}
     */
    private function mission(): array
    {
        $course = Course::factory()->create();

        return [$course, Challenge::factory()->for($course)->create()];
    }

    private function defaultDrone(): DroneModel
    {
        return DroneModel::query()->fleetDefault()->sole();
    }

    private function droneNamed(string $slug): DroneModel
    {
        return DroneModel::query()->where('slug', $slug)->sole();
    }

    /**
     * A run that takes off and lands, which clears an unconstrained mission.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function flight(array $overrides = []): array
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
}
