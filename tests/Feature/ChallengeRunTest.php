<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Challenge;
use App\Models\ChallengeRun;
use App\Models\Course;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The append-only attempt history behind advanced analytics.
 *
 * These are the properties the progress row cannot hold: that a run survives
 * even when it was worse than the last one, that what is stored is what the
 * server graded rather than what the client claimed, and that a run and its
 * merge into progress never come apart.
 */
final class ChallengeRunTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_submitted_run_is_recorded(): void
    {
        $user = User::factory()->create();
        $course = Course::factory()->create();
        $challenge = Challenge::factory()->for($course)->create();

        foreach (range(1, 3) as $ignored) {
            $this->actingAs($user)->postJson(
                route('challenges.attempts.store', [$course, $challenge]),
                $this->flight(),
            )->assertOk();
        }

        $this->assertSame(3, ChallengeRun::query()
            ->where('user_id', $user->id)
            ->where('challenge_id', $challenge->id)
            ->count());
    }

    public function test_a_worse_run_is_still_recorded_even_though_progress_ignores_it(): void
    {
        $user = User::factory()->create();
        $course = Course::factory()->create();
        $challenge = Challenge::factory()->for($course)->create(['max_score' => 100]);

        // A clean landing clears the mission outright.
        $this->actingAs($user)->postJson(
            route('challenges.attempts.store', [$course, $challenge]),
            $this->flight(),
        )->assertJsonPath('result.score', 100);

        // A run that never lands scores less on a mission that requires one.
        $this->actingAs($user)->postJson(
            route('challenges.attempts.store', [$course, $challenge]),
            $this->flight(['path' => $this->hoveringPath()]),
        )->assertJsonPath('result.completed', false);

        $runs = ChallengeRun::query()
            ->where('user_id', $user->id)
            ->orderBy('id')
            ->get();

        $this->assertCount(2, $runs, 'the poorer run was dropped instead of logged');
        $this->assertSame(100, $runs[0]->score);
        $this->assertTrue($runs[0]->completed);
        $this->assertLessThan(100, $runs[1]->score);
        $this->assertFalse($runs[1]->completed);

        // And progress kept the better of the two, as it always has.
        $this->assertDatabaseHas('user_challenge_progress', [
            'user_id' => $user->id,
            'challenge_id' => $challenge->id,
            'best_score' => 100,
            'attempts' => 2,
        ]);
    }

    public function test_a_run_records_what_the_server_graded_not_what_the_client_sent(): void
    {
        $user = User::factory()->create();
        $course = Course::factory()->create();
        $challenge = Challenge::factory()->for($course)->create(['max_score' => 100]);

        // These keys are not in the request contract at all. If any of them
        // ever reached the stored row, the run table would be as forgeable as
        // a self-reported score.
        $this->actingAs($user)->postJson(
            route('challenges.attempts.store', [$course, $challenge]),
            $this->flight([
                'score' => 999,
                'stars' => 3,
                'completed' => true,
                'collisions' => 7,
            ]),
        )->assertOk();

        $run = ChallengeRun::query()->firstOrFail();

        $this->assertLessThanOrEqual($challenge->max_score, $run->score);
        $this->assertNotSame(999, $run->score);
        $this->assertLessThanOrEqual(3, $run->stars);

        /*
         * The collision count is the one number the client does contribute,
         * and the reconstruction takes the higher of the two — a pilot may
         * confess to hitting something the replay missed, never conceal one.
         * Seven collisions on a collision-sensitive mission is seventy points
         * of penalty, and the stored score wears it.
         */
        $this->assertSame(7, $run->collisions);
        $this->assertSame(30, $run->score);
    }

    public function test_a_run_records_the_objectives_its_score_was_built_from(): void
    {
        $user = User::factory()->create();
        $course = Course::factory()->create();
        $challenge = Challenge::factory()->for($course)->create([
            'success_criteria' => [
                'type' => 'waypoints',
                'waypoints' => [
                    ['x' => 0, 'y' => 2, 'z' => 0, 'radius' => 1.5],
                    ['x' => 0, 'y' => 2, 'z' => -40, 'radius' => 1.5],
                ],
                'avoid_collisions' => true,
                'max_time_seconds' => 60,
                'landing_required' => true,
            ],
        ]);

        // A flight that reaches the first waypoint and not the second.
        $this->actingAs($user)->postJson(
            route('challenges.attempts.store', [$course, $challenge]),
            $this->flight(),
        )->assertOk();

        $run = ChallengeRun::query()->firstOrFail();

        $this->assertSame(2, $run->objectives_total);
        $this->assertSame(1, $run->objectives_hit);
    }

    public function test_a_run_is_never_recorded_for_a_mission_the_pilot_cannot_reach(): void
    {
        $user = User::factory()->create();
        $course = Course::factory()->requiring(\App\Enums\Plan::Pro)->create();
        $challenge = Challenge::factory()->for($course)->create();

        $this->actingAs($user)->postJson(
            route('challenges.attempts.store', [$course, $challenge]),
            $this->flight(),
        )->assertForbidden();

        $this->assertSame(0, ChallengeRun::query()->count());
    }

    public function test_a_run_carries_a_uuid_and_is_addressed_by_it(): void
    {
        $run = ChallengeRun::factory()->create();

        $this->assertNotNull($run->uuid);
        $this->assertSame('uuid', $run->getRouteKeyName());
        $this->assertSame($run->uuid, $run->getRouteKey());
    }

    /**
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

    /**
     * A run that takes off and stays up, so it never satisfies a landing.
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
}
