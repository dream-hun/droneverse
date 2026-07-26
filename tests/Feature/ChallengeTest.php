<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\ChallengeStatus;
use App\Models\Challenge;
use App\Models\Course;
use App\Models\User;
use App\Models\UserChallengeProgress;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class ChallengeTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_to_the_login_page(): void
    {
        $course = Course::factory()->create();
        $challenge = Challenge::factory()->for($course)->create();

        $response = $this->get(route('challenges.show', [$course, $challenge]));

        $response->assertRedirect(route('login'));
    }

    public function test_authenticated_users_can_view_the_simulator(): void
    {
        $user = User::factory()->create();
        $course = Course::factory()->create();
        $challenge = Challenge::factory()->for($course)->create();

        $response = $this->actingAs($user)->get(route('challenges.show', [$course, $challenge]));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('challenges/play')
            ->where('challenge.slug', $challenge->slug)
            ->where('progress.status', ChallengeStatus::NotStarted));
    }

    public function test_the_reference_solution_is_withheld_on_a_first_visit(): void
    {
        $user = User::factory()->create();
        $course = Course::factory()->create();
        $challenge = Challenge::factory()->for($course)->create();

        $response = $this->actingAs($user)->get(route('challenges.show', [$course, $challenge]));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->where('solution.exists', true)
            ->where('solution.unlocked', false)
            ->where('solution.code', null)
            ->where('solution.attemptsRequired', Challenge::ATTEMPTS_BEFORE_SOLUTION));
    }

    public function test_the_reference_solution_stays_locked_below_the_attempt_threshold(): void
    {
        $user = User::factory()->create();
        $course = Course::factory()->create();
        $challenge = Challenge::factory()->for($course)->create();

        UserChallengeProgress::factory()->create([
            'user_id' => $user->id,
            'challenge_id' => $challenge->id,
            'attempts' => Challenge::ATTEMPTS_BEFORE_SOLUTION - 1,
        ]);

        $response = $this->actingAs($user)->get(route('challenges.show', [$course, $challenge]));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->where('solution.unlocked', false)
            ->where('solution.code', null));
    }

    public function test_the_reference_solution_unlocks_once_the_attempt_threshold_is_reached(): void
    {
        $user = User::factory()->create();
        $course = Course::factory()->create();
        $challenge = Challenge::factory()->for($course)->create();

        UserChallengeProgress::factory()->create([
            'user_id' => $user->id,
            'challenge_id' => $challenge->id,
            'attempts' => Challenge::ATTEMPTS_BEFORE_SOLUTION,
        ]);

        $response = $this->actingAs($user)->get(route('challenges.show', [$course, $challenge]));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->where('solution.unlocked', true)
            ->where('solution.code', $challenge->solution_code));
    }

    public function test_completing_a_challenge_unlocks_the_reference_solution_immediately(): void
    {
        $user = User::factory()->create();
        $course = Course::factory()->create();
        $challenge = Challenge::factory()->for($course)->create();

        UserChallengeProgress::factory()->completed()->create([
            'user_id' => $user->id,
            'challenge_id' => $challenge->id,
            'attempts' => 1,
        ]);

        $response = $this->actingAs($user)->get(route('challenges.show', [$course, $challenge]));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->where('solution.unlocked', true)
            ->where('solution.code', $challenge->solution_code));
    }

    public function test_a_challenge_without_a_reference_solution_never_unlocks_one(): void
    {
        $user = User::factory()->create();
        $course = Course::factory()->create();
        $challenge = Challenge::factory()->for($course)->withoutSolution()->create();

        UserChallengeProgress::factory()->completed()->create([
            'user_id' => $user->id,
            'challenge_id' => $challenge->id,
            'attempts' => 10,
        ]);

        $response = $this->actingAs($user)->get(route('challenges.show', [$course, $challenge]));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->where('solution.exists', false)
            ->where('solution.unlocked', false)
            ->where('solution.code', null));
    }

    public function test_challenge_from_a_different_course_returns_not_found(): void
    {
        $user = User::factory()->create();
        $course = Course::factory()->create();
        $otherCourse = Course::factory()->create();
        $challenge = Challenge::factory()->for($otherCourse)->create();

        $response = $this->actingAs($user)->get(route('challenges.show', [$course, $challenge]));

        $response->assertNotFound();
    }

    public function test_submitting_a_run_creates_progress(): void
    {
        $user = User::factory()->create();
        $course = Course::factory()->create();
        $challenge = Challenge::factory()->for($course)->create(['max_score' => 100]);

        $response = $this->actingAs($user)->postJson(
            route('challenges.attempts.store', [$course, $challenge]),
            $this->flight(),
        );

        $response->assertOk();
        $response->assertJsonPath('result.completed', true);
        $response->assertJsonPath('result.score', 100);
        $response->assertJsonPath('result.stars', 3);
        $response->assertJsonPath('progress.status', ChallengeStatus::Completed->value);
        $response->assertJsonPath('progress.attempts', 1);

        $this->assertDatabaseHas('user_challenge_progress', [
            'user_id' => $user->id,
            'challenge_id' => $challenge->id,
            'status' => ChallengeStatus::Completed->value,
            'best_score' => 100,
            'stars' => 3,
            'attempts' => 1,
        ]);
    }

    public function test_a_client_supplied_score_is_ignored(): void
    {
        $user = User::factory()->create();
        $course = Course::factory()->create();
        $challenge = Challenge::factory()->for($course)->create(['max_score' => 100]);

        // The old contract; a pilot posting these hoped to award themselves a
        // finished mission without flying one.
        $response = $this->actingAs($user)->postJson(
            route('challenges.attempts.store', [$course, $challenge]),
            $this->flight([
                'score' => 100,
                'stars' => 3,
                'completed' => true,
                'path' => $this->hoveringPath(),
            ]),
        );

        $response->assertOk();
        $response->assertJsonPath('result.completed', false);
        $response->assertJsonPath('result.stars', 0);
        $this->assertLessThan(100, $response->json('result.score'));
    }

    public function test_a_run_is_graded_against_the_waypoints_it_actually_flew(): void
    {
        $user = User::factory()->create();
        $course = Course::factory()->create();
        $challenge = $this->challengeWithWaypoint($course);

        $missed = $this->actingAs($user)->postJson(
            route('challenges.attempts.store', [$course, $challenge]),
            $this->flight(['path' => [
                ['t' => 0, 'x' => 0, 'y' => 0.15, 'z' => 0],
                ['t' => 2, 'x' => 20, 'y' => 2, 'z' => 20],
                ['t' => 3, 'x' => 20, 'y' => 0.15, 'z' => 20],
            ]]),
        );

        $missed->assertJsonPath('result.waypointsHit', 0);
        $missed->assertJsonPath('result.completed', false);

        $flown = $this->actingAs($user)->postJson(
            route('challenges.attempts.store', [$course, $challenge]),
            $this->flight(['path' => [
                ['t' => 0, 'x' => 0, 'y' => 0.15, 'z' => 0],
                ['t' => 2, 'x' => 5, 'y' => 2, 'z' => 0],
                ['t' => 3, 'x' => 5, 'y' => 0.15, 'z' => 0],
            ]]),
        );

        $flown->assertJsonPath('result.waypointsHit', 1);
        $flown->assertJsonPath('result.completed', true);
    }

    /**
     * The path arrives sampled, so a waypoint can fall between two points.
     * Grading measures each segment rather than its endpoints, or a fast
     * pass through a small waypoint would be scored as a miss.
     */
    public function test_a_waypoint_crossed_between_two_samples_still_counts(): void
    {
        $user = User::factory()->create();
        $course = Course::factory()->create();
        $challenge = $this->challengeWithWaypoint($course);

        $response = $this->actingAs($user)->postJson(
            route('challenges.attempts.store', [$course, $challenge]),
            $this->flight(['path' => [
                ['t' => 0, 'x' => 0, 'y' => 2, 'z' => 0],
                // Straight through (5, 2, 0) without ever sampling there.
                ['t' => 1, 'x' => 10, 'y' => 2, 'z' => 0],
                ['t' => 2, 'x' => 10, 'y' => 0.15, 'z' => 0],
            ]]),
        );

        $response->assertJsonPath('result.waypointsHit', 1);
    }

    public function test_flying_through_solid_geometry_counts_as_a_collision(): void
    {
        $user = User::factory()->create();
        $course = Course::factory()->create();
        $challenge = Challenge::factory()->for($course)->create([
            'max_score' => 100,
            'environment' => [
                'start' => ['x' => 0, 'y' => 0.5, 'z' => 0],
                'obstacles' => [
                    ['type' => 'box', 'x' => 5, 'y' => 3, 'z' => 0, 'sx' => 6, 'sy' => 6, 'sz' => 6],
                ],
                'gates' => [],
                'waypoints' => [],
            ],
        ]);

        $response = $this->actingAs($user)->postJson(
            route('challenges.attempts.store', [$course, $challenge]),
            $this->flight([
                'collisions' => 0,
                'path' => [
                    ['t' => 0, 'x' => 0, 'y' => 3, 'z' => 0],
                    ['t' => 1, 'x' => 5, 'y' => 3, 'z' => 0],
                    ['t' => 2, 'x' => 10, 'y' => 3, 'z' => 0],
                    ['t' => 3, 'x' => 10, 'y' => 0.15, 'z' => 0],
                ],
            ]),
        );

        $response->assertJsonPath('result.collisions', 1);
        // Completion survives the strike, but the clean-flight star does not.
        $response->assertJsonPath('result.stars', 2);
    }

    public function test_a_run_that_never_lands_does_not_complete(): void
    {
        $user = User::factory()->create();
        $course = Course::factory()->create();
        $challenge = Challenge::factory()->for($course)->create(['max_score' => 100]);

        $response = $this->actingAs($user)->postJson(
            route('challenges.attempts.store', [$course, $challenge]),
            $this->flight(['path' => $this->hoveringPath()]),
        );

        $response->assertJsonPath('result.landed', false);
        $response->assertJsonPath('result.completed', false);
    }

    public function test_a_run_over_the_time_limit_is_timed_out(): void
    {
        $user = User::factory()->create();
        $course = Course::factory()->create();
        $challenge = Challenge::factory()->for($course)->create(['max_score' => 100]);

        $response = $this->actingAs($user)->postJson(
            route('challenges.attempts.store', [$course, $challenge]),
            $this->flight(['path' => [
                ['t' => 0, 'x' => 0, 'y' => 0.15, 'z' => 0],
                ['t' => 75, 'x' => 0, 'y' => 0.15, 'z' => 0],
            ]]),
        );

        $response->assertJsonPath('result.timedOut', true);
        $response->assertJsonPath('result.completed', false);
    }

    public function test_photo_targets_are_matched_against_where_photos_were_taken(): void
    {
        $user = User::factory()->create();
        $course = Course::factory()->create();
        $challenge = Challenge::factory()->for($course)->create([
            'max_score' => 100,
            'success_criteria' => [
                'type' => 'waypoints',
                'waypoints' => [],
                'avoid_collisions' => true,
                'max_time_seconds' => 60,
                'landing_required' => true,
                'photo_targets' => [['x' => 12, 'z' => -4, 'radius' => 3]],
            ],
        ]);

        $elsewhere = $this->actingAs($user)->postJson(
            route('challenges.attempts.store', [$course, $challenge]),
            $this->flight(['photos' => [['x' => -30, 'y' => 4, 'z' => 30]]]),
        );

        $elsewhere->assertJsonPath('result.photoTargetsHit', 0);
        $elsewhere->assertJsonPath('result.completed', false);

        $onTarget = $this->actingAs($user)->postJson(
            route('challenges.attempts.store', [$course, $challenge]),
            $this->flight(['photos' => [['x' => 12.5, 'y' => 4, 'z' => -3]]]),
        );

        $onTarget->assertJsonPath('result.photoTargetsHit', 1);
        $onTarget->assertJsonPath('result.completed', true);
    }

    public function test_a_wash_pass_is_confirmed_from_the_flight_path(): void
    {
        $user = User::factory()->create();
        $course = Course::factory()->create();
        $challenge = Challenge::factory()->for($course)->create([
            'max_score' => 100,
            'environment' => [
                'start' => ['x' => 0, 'y' => 0.5, 'z' => 0],
                'obstacles' => [],
                'gates' => [],
                'waypoints' => [],
                'carwash' => ['x' => 0, 'z' => 0, 'width' => 4.5, 'height' => 3.5, 'length' => 8],
            ],
            'success_criteria' => [
                'type' => 'waypoints',
                'waypoints' => [],
                'avoid_collisions' => false,
                'max_time_seconds' => 60,
                'landing_required' => false,
                'wash_required' => true,
            ],
        ]);

        $skipped = $this->actingAs($user)->postJson(
            route('challenges.attempts.store', [$course, $challenge]),
            $this->flight(['path' => [
                ['t' => 0, 'x' => 20, 'y' => 2, 'z' => 20],
                ['t' => 2, 'x' => 25, 'y' => 2, 'z' => 25],
            ]]),
        );

        $skipped->assertJsonPath('result.washed', false);
        $skipped->assertJsonPath('result.completed', false);

        // Straight down the tunnel's axis, through both sensor mouths.
        $driven = $this->actingAs($user)->postJson(
            route('challenges.attempts.store', [$course, $challenge]),
            $this->flight(['path' => [
                ['t' => 0, 'x' => 0, 'y' => 1.5, 'z' => 6],
                ['t' => 1, 'x' => 0, 'y' => 1.5, 'z' => 3.5],
                ['t' => 2, 'x' => 0, 'y' => 1.5, 'z' => 0],
                ['t' => 3, 'x' => 0, 'y' => 1.5, 'z' => -3.5],
                ['t' => 4, 'x' => 0, 'y' => 1.5, 'z' => -6],
            ]]),
        );

        $driven->assertJsonPath('result.washed', true);
        $driven->assertJsonPath('result.completed', true);
    }

    public function test_runs_on_an_unpublished_challenge_are_rejected(): void
    {
        $user = User::factory()->create();
        $course = Course::factory()->create();
        $challenge = Challenge::factory()->for($course)->unpublished()->create();

        $response = $this->actingAs($user)->postJson(
            route('challenges.attempts.store', [$course, $challenge]),
            $this->flight(),
        );

        $response->assertNotFound();
        $this->assertDatabaseMissing('user_challenge_progress', [
            'user_id' => $user->id,
            'challenge_id' => $challenge->id,
        ]);
    }

    public function test_runs_on_a_challenge_in_an_unpublished_course_are_rejected(): void
    {
        $user = User::factory()->create();
        $course = Course::factory()->unpublished()->create();
        $challenge = Challenge::factory()->for($course)->create();

        $response = $this->actingAs($user)->postJson(
            route('challenges.attempts.store', [$course, $challenge]),
            $this->flight(),
        );

        $response->assertNotFound();
        $this->assertDatabaseMissing('user_challenge_progress', [
            'user_id' => $user->id,
            'challenge_id' => $challenge->id,
        ]);
    }

    public function test_a_run_without_a_flight_path_is_rejected(): void
    {
        $user = User::factory()->create();
        $course = Course::factory()->create();
        $challenge = Challenge::factory()->for($course)->create();

        $response = $this->actingAs($user)->postJson(
            route('challenges.attempts.store', [$course, $challenge]),
            ['code' => 'async function main(drone) {}', 'collisions' => 0, 'photos' => []],
        );

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors('path');
    }

    public function test_an_incomplete_first_run_marks_the_challenge_in_progress(): void
    {
        $user = User::factory()->create();
        $course = Course::factory()->create();
        $challenge = Challenge::factory()->for($course)->create(['max_score' => 100]);

        $response = $this->actingAs($user)->postJson(
            route('challenges.attempts.store', [$course, $challenge]),
            $this->flight(['path' => $this->hoveringPath()]),
        );

        $response->assertOk();
        $response->assertJsonPath('progress.status', ChallengeStatus::InProgress->value);
        $response->assertJsonPath('progress.attempts', 1);

        $this->assertDatabaseHas('user_challenge_progress', [
            'user_id' => $user->id,
            'challenge_id' => $challenge->id,
            'status' => ChallengeStatus::InProgress->value,
        ]);
    }

    public function test_a_completed_challenge_is_not_downgraded_by_a_later_incomplete_run(): void
    {
        $user = User::factory()->create();
        $course = Course::factory()->create();
        $challenge = Challenge::factory()->for($course)->create(['max_score' => 100]);

        UserChallengeProgress::factory()->completed()->create([
            'user_id' => $user->id,
            'challenge_id' => $challenge->id,
        ]);

        $response = $this->actingAs($user)->postJson(
            route('challenges.attempts.store', [$course, $challenge]),
            $this->flight(['path' => $this->hoveringPath()]),
        );

        $response->assertOk();
        $response->assertJsonPath('progress.status', ChallengeStatus::Completed->value);
        $response->assertJsonPath('progress.attempts', 2);
    }

    public function test_best_score_never_decreases_across_runs(): void
    {
        $user = User::factory()->create();
        $course = Course::factory()->create();
        $challenge = Challenge::factory()->for($course)->create(['max_score' => 100]);

        UserChallengeProgress::factory()->create([
            'user_id' => $user->id,
            'challenge_id' => $challenge->id,
            'best_score' => 95,
            'stars' => 3,
            'attempts' => 1,
        ]);

        $response = $this->actingAs($user)->postJson(
            route('challenges.attempts.store', [$course, $challenge]),
            $this->flight(['path' => $this->hoveringPath()]),
        );

        $response->assertOk();
        $response->assertJsonPath('progress.bestScore', 95);
        $response->assertJsonPath('progress.stars', 3);
        $response->assertJsonPath('progress.attempts', 2);
    }

    /**
     * A clean run: lift off, then settle back onto the pad well inside the
     * time limit.
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
                ['t' => 1, 'x' => 0, 'y' => 1.5, 'z' => 0],
                ['t' => 2, 'x' => 0, 'y' => 0.15, 'z' => 0],
            ],
            'photos' => [],
        ], $overrides);
    }

    /**
     * A run that takes off and stays up: it never lands, so it never
     * completes a mission that requires one.
     *
     * @return array<int, array<string, float|int>>
     */
    private function hoveringPath(): array
    {
        return [
            ['t' => 0, 'x' => 0, 'y' => 0.15, 'z' => 0],
            ['t' => 1, 'x' => 0, 'y' => 2, 'z' => 0],
            ['t' => 2, 'x' => 0, 'y' => 2, 'z' => 0],
        ];
    }

    /** A mission with a single waypoint five metres out along +X. */
    private function challengeWithWaypoint(Course $course): Challenge
    {
        return Challenge::factory()->for($course)->create([
            'max_score' => 100,
            'success_criteria' => [
                'type' => 'waypoints',
                'waypoints' => [['x' => 5, 'y' => 2, 'z' => 0, 'radius' => 1.5]],
                'avoid_collisions' => true,
                'max_time_seconds' => 60,
                'landing_required' => true,
            ],
        ]);
    }
}
