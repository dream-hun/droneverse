<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Actions\RebuildRollups;
use App\Enums\ChallengeStatus;
use App\Models\Challenge;
use App\Models\ChallengeRun;
use App\Models\Course;
use App\Models\PilotCourseTotals;
use App\Models\PilotMissionStats;
use App\Models\User;
use App\Models\UserChallengeProgress;
use App\Queries\FlightLog;
use App\Queries\Leaderboard;
use Carbon\CarbonInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * What the read-model rollups are required to hold.
 *
 * `pilot_mission_stats` and `pilot_course_totals` are derived data, and the
 * whole argument for them is that they say the same thing the tables behind
 * them do, only cheaper. So the load-bearing test here is not that the
 * numbers look right — the analytics and leaderboard suites already check
 * that through the read models — it is that the path production takes to
 * maintain them and the path that recomputes them from scratch never
 * disagree. If they can, the rollups are a second definition of the truth
 * rather than a cache of it.
 */
final class RollupTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The property that makes an incremental rollup safe to trust.
     *
     * `pilot_mission_stats` counts runs as they land rather than recomputing,
     * because recomputing would mean re-reading the history it exists to stop
     * reading. That trade is only sound while the counting agrees with the
     * recomputation, which is what this asserts over a history containing
     * every case the columns distinguish: misses before a clear, the clear
     * itself, runs after it, clean runs and dirty ones, and a mission that
     * was never cleared at all.
     */
    public function test_counting_runs_as_they_land_agrees_with_rebuilding_from_them(): void
    {
        $this->flyAVariedHistory();

        $incremental = $this->missionStatsSnapshot();

        resolve(RebuildRollups::class)->missionStats();

        $this->assertSame(
            $incremental,
            $this->missionStatsSnapshot(),
            'the totals kept as runs landed are not the totals the runs add up to',
        );
    }

    public function test_recomputing_course_totals_agrees_with_rebuilding_them(): void
    {
        $this->flyAVariedHistory();

        $incremental = $this->courseTotalsSnapshot();

        resolve(RebuildRollups::class)->courseTotals();

        $this->assertSame(
            $incremental,
            $this->courseTotalsSnapshot(),
            'the totals kept as progress moved are not the totals the progress adds up to',
        );
    }

    /**
     * The case that broke the backfill.
     *
     * A pilot who has flown in a course but completed nothing in it groups to
     * a null finish time, and on MySQL that null does not survive
     * `insert ... select` unaided — `max()` over a `timestamp` hands the
     * insert the zero date instead, and the strict `sql_mode` rejects the row,
     * taking the whole rebuild with it. This suite runs on SQLite and cannot
     * reproduce that half.
     *
     * What it can hold is the other half, and it is the half worth guarding:
     * that whatever the rebuild does to keep that null a null leaves a real
     * finish time intact on the way past. The obvious fix — casting to
     * `datetime` — reads as a no-op and is not one on SQLite, where the type
     * name carries numeric affinity and would quietly reduce a completion to
     * the integer year.
     */
    public function test_a_rebuild_keeps_finish_times_a_pilot_has_and_nulls_the_ones_they_do_not(): void
    {
        $pilot = User::factory()->create();

        $unfinishedCourse = Course::factory()->create();
        $this->progress($pilot, Challenge::factory()->for($unfinishedCourse)->create(['max_score' => 100]), points: 30);

        $finishedCourse = Course::factory()->create();
        $cleared = Challenge::factory()->for($finishedCourse)->create(['max_score' => 100]);
        $this->progress($pilot, $cleared, points: 90, completed: true);

        $completedAt = UserChallengeProgress::query()
            ->where('user_id', $pilot->id)
            ->where('challenge_id', $cleared->id)
            ->value('completed_at');

        resolve(RebuildRollups::class)->courseTotals();

        $this->assertNull(
            $this->finishedAtFor($pilot, $unfinishedCourse),
            'a course the pilot has finished nothing in was given a finish time',
        );

        $this->assertSame(
            $completedAt->toIso8601String(),
            $this->finishedAtFor($pilot, $finishedCourse)?->toIso8601String(),
            'the rebuild did not carry the completion time through intact',
        );
    }

    public function test_attempts_to_clear_freezes_at_the_run_that_cleared_the_mission(): void
    {
        $user = User::factory()->create();
        $challenge = Challenge::factory()->create();

        ChallengeRun::factory()->count(2)->create([
            'user_id' => $user->id,
            'challenge_id' => $challenge->id,
        ]);
        ChallengeRun::factory()->completed()->create([
            'user_id' => $user->id,
            'challenge_id' => $challenge->id,
        ]);
        // Chasing stars on a mission already beaten. Counting these would
        // make a pilot who kept practising look slower than one who moved on.
        ChallengeRun::factory()->count(4)->completed()->create([
            'user_id' => $user->id,
            'challenge_id' => $challenge->id,
        ]);

        $stats = PilotMissionStats::query()
            ->where('user_id', $user->id)
            ->where('challenge_id', $challenge->id)
            ->sole();

        $this->assertSame(3, $stats->attempts_to_clear);
        $this->assertSame(7, $stats->runs);
        $this->assertTrue($stats->cleared);
    }

    public function test_a_mission_never_cleared_records_no_attempts_to_clear(): void
    {
        // Null rather than zero, which is what keeps an uncleared mission out
        // of the mean instead of dragging it to the floor.
        $user = User::factory()->create();
        $challenge = Challenge::factory()->create();

        ChallengeRun::factory()->count(3)->create([
            'user_id' => $user->id,
            'challenge_id' => $challenge->id,
        ]);

        $this->assertNull(
            PilotMissionStats::query()
                ->where('user_id', $user->id)
                ->where('challenge_id', $challenge->id)
                ->value('attempts_to_clear'),
        );
    }

    public function test_a_rebuild_corrects_a_rollup_that_has_gone_wrong(): void
    {
        /*
         * The reason `rollups:rebuild` exists. An incremental total can be
         * left wrong by a bug, a partial restore or someone in the database
         * by hand, and no future run will correct it — the next one just adds
         * to a number that was already off.
         */
        $user = User::factory()->create();
        $challenge = Challenge::factory()->create();

        ChallengeRun::factory()->count(3)->create([
            'user_id' => $user->id,
            'challenge_id' => $challenge->id,
        ]);

        PilotMissionStats::query()
            ->where('user_id', $user->id)
            ->update(['runs' => 999, 'best_score' => 999]);

        $this->artisan('rollups:rebuild')->assertSuccessful();

        $stats = PilotMissionStats::query()->where('user_id', $user->id)->sole();

        $this->assertSame(3, $stats->runs);
        $this->assertNotSame(999, $stats->best_score);
    }

    public function test_a_rebuild_drops_a_rollup_row_whose_runs_are_gone(): void
    {
        // A rebuild replaces the tables rather than patching them, so a row
        // with nothing behind it does not survive one.
        $orphan = PilotMissionStats::factory()->create(['runs' => 12]);

        resolve(RebuildRollups::class)->missionStats();

        $this->assertDatabaseMissing('pilot_mission_stats', ['id' => $orphan->id]);
    }

    public function test_retiring_a_mission_takes_its_points_off_the_board(): void
    {
        /*
         * The one live predicate the leaderboard rollup swallows, and so the
         * one that needs a hook. Course-level publication stays a join on the
         * read side and needs nothing; per-mission publication is baked into
         * the stored total, so without ChallengeObserver the board would go
         * on serving points for a mission nobody can fly.
         */
        $course = Course::factory()->create();
        $kept = Challenge::factory()->for($course)->create(['max_score' => 100]);
        $retired = Challenge::factory()->for($course)->create(['max_score' => 100]);
        $pilot = User::factory()->create();

        $this->progress($pilot, $kept, points: 30);
        $this->progress($pilot, $retired, points: 70);

        $this->assertSame(100, $this->pointsFor($pilot, $course));

        $retired->update(['is_published' => false]);

        $this->assertSame(
            30,
            $this->pointsFor($pilot, $course),
            'a retired mission kept its points on the board',
        );
    }

    public function test_publishing_a_mission_puts_its_points_back(): void
    {
        $course = Course::factory()->create();
        $challenge = Challenge::factory()->for($course)->unpublished()->create(['max_score' => 100]);
        $pilot = User::factory()->create();

        $this->progress($pilot, $challenge, points: 55);

        $this->assertNull($this->pointsFor($pilot, $course));

        $challenge->update(['is_published' => true]);

        $this->assertSame(55, $this->pointsFor($pilot, $course));
    }

    public function test_a_pilot_with_nothing_playable_left_has_no_row_at_all(): void
    {
        /*
         * A row of zeroes and no row are different things to the board. The
         * standings list pilots who have flown something that still counts,
         * so a pilot whose only mission was retired has to disappear rather
         * than appear on nil points.
         */
        $course = Course::factory()->create();
        $challenge = Challenge::factory()->for($course)->create(['max_score' => 100]);
        $pilot = User::factory()->create();

        $this->progress($pilot, $challenge, points: 40);

        $this->assertSame(1, resolve(Leaderboard::class)->rankedPilotCount());

        $challenge->update(['is_published' => false]);

        $this->assertDatabaseMissing('pilot_course_totals', [
            'user_id' => $pilot->id,
            'course_id' => $course->id,
        ]);
        $this->assertSame(0, resolve(Leaderboard::class)->rankedPilotCount());
    }

    public function test_moving_a_mission_between_courses_rebuilds_both_of_them(): void
    {
        $from = Course::factory()->create();
        $to = Course::factory()->create();
        $challenge = Challenge::factory()->for($from)->create(['max_score' => 100]);
        $pilot = User::factory()->create();

        $this->progress($pilot, $challenge, points: 60);

        $this->assertSame(60, $this->pointsFor($pilot, $from));

        $challenge->update(['course_id' => $to->id]);

        $this->assertNull($this->pointsFor($pilot, $from), 'the old course kept points it no longer holds');
        $this->assertSame(60, $this->pointsFor($pilot, $to));
    }

    public function test_the_read_models_never_touch_the_raw_tables_for_a_total(): void
    {
        /*
         * The point of the rollups. Every slice but the curve is a total, and
         * a total that still scanned `challenge_runs` would cost more to read
         * the more a pilot had flown — which is the growth these tables were
         * added to stop.
         */
        $user = $this->flyAVariedHistory();
        $flightLog = resolve(FlightLog::class);

        DB::enableQueryLog();
        $flightLog->summaryFor($user);
        $flightLog->weakSpots($user);
        $flightLog->flownMissions($user);

        $queries = DB::getRawQueryLog();
        DB::disableQueryLog();

        $overRuns = array_values(array_filter(
            array_map(fn (array $query): string => $query['raw_query'], $queries),
            fn (string $sql): bool => str_contains($sql, 'challenge_runs'),
        ));

        $this->assertSame([], $overRuns, 'a total was still aggregated over the run table');
    }

    /**
     * A pilot with the kinds of history the rollup columns distinguish.
     *
     * Two courses, four missions: one cleared after a couple of misses, one
     * cleared on the first run, one never cleared, and one flown once. Runs
     * are created through the factory, which is the same path the recording
     * action takes into the observer that maintains the rollup.
     */
    private function flyAVariedHistory(): User
    {
        $pilot = User::factory()->create();

        $firstCourse = Course::factory()->create();
        $secondCourse = Course::factory()->create();

        $struggled = Challenge::factory()->for($firstCourse)->create(['max_score' => 100]);
        $straightaway = Challenge::factory()->for($firstCourse)->create(['max_score' => 100]);
        $stuck = Challenge::factory()->for($secondCourse)->create(['max_score' => 100]);
        $touched = Challenge::factory()->for($secondCourse)->create(['max_score' => 100]);

        ChallengeRun::factory()->count(2)->scoring(20)->create([
            'user_id' => $pilot->id,
            'challenge_id' => $struggled->id,
        ]);
        ChallengeRun::factory()->completed()->scoring(80)->create([
            'user_id' => $pilot->id,
            'challenge_id' => $struggled->id,
        ]);
        ChallengeRun::factory()->scoring(45)->create([
            'user_id' => $pilot->id,
            'challenge_id' => $struggled->id,
        ]);

        ChallengeRun::factory()->completed()->scoring(95)->create([
            'user_id' => $pilot->id,
            'challenge_id' => $straightaway->id,
        ]);

        ChallengeRun::factory()->count(5)->scoring(30)->create([
            'user_id' => $pilot->id,
            'challenge_id' => $stuck->id,
        ]);

        ChallengeRun::factory()->scoring(10)->create([
            'user_id' => $pilot->id,
            'challenge_id' => $touched->id,
        ]);

        $this->progress($pilot, $struggled, points: 80, completed: true);
        $this->progress($pilot, $straightaway, points: 95, completed: true);
        $this->progress($pilot, $stuck, points: 30);
        $this->progress($pilot, $touched, points: 10);

        // A second pilot, so the cohort columns are not all populations of
        // one and a rebuild has more than a single row to get right.
        $rival = User::factory()->create();
        ChallengeRun::factory()->count(3)->completed()->scoring(70)->create([
            'user_id' => $rival->id,
            'challenge_id' => $stuck->id,
        ]);
        $this->progress($rival, $stuck, points: 70, completed: true);

        return $pilot;
    }

    private function progress(User $user, Challenge $challenge, int $points, bool $completed = false): void
    {
        UserChallengeProgress::query()->create([
            'user_id' => $user->id,
            'challenge_id' => $challenge->id,
            'best_score' => $points,
            'stars' => $completed ? 3 : 0,
            'attempts' => 1,
            'status' => $completed ? ChallengeStatus::Completed : ChallengeStatus::InProgress,
            'completed_at' => $completed ? now() : null,
        ]);
    }

    /**
     * The stored points for one pilot in one course, or null if they have no
     * standing there at all.
     */
    private function pointsFor(User $user, Course $course): ?int
    {
        $points = PilotCourseTotals::query()
            ->where('user_id', $user->id)
            ->where('course_id', $course->id)
            ->value('points');

        return $points === null ? null : (int) $points;
    }

    /**
     * The stored finish time for one pilot in one course.
     */
    private function finishedAtFor(User $user, Course $course): ?CarbonInterface
    {
        return PilotCourseTotals::query()
            ->where('user_id', $user->id)
            ->where('course_id', $course->id)
            ->value('finished_at');
    }

    /**
     * Every mission-stats row, in a form two runs of the suite can compare.
     *
     * Keyed and ordered by the pairing rather than by id, because a rebuild
     * writes new rows and their ids are not part of what the rollup means.
     *
     * @return array<string, array<string, mixed>>
     */
    private function missionStatsSnapshot(): array
    {
        return PilotMissionStats::query()
            ->orderBy('user_id')
            ->orderBy('challenge_id')
            ->get()
            ->mapWithKeys(fn (PilotMissionStats $stats): array => [
                sprintf('%d:%d', $stats->user_id, $stats->challenge_id) => [
                    'runs' => $stats->runs,
                    'clean_runs' => $stats->clean_runs,
                    'cleared' => $stats->cleared,
                    'best_score' => $stats->best_score,
                    'collisions_total' => $stats->collisions_total,
                    'elapsed_seconds_total' => round($stats->elapsed_seconds_total, 2),
                    'attempts_to_clear' => $stats->attempts_to_clear,
                    'last_run_id' => $stats->last_run_id,
                ],
            ])
            ->all();
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function courseTotalsSnapshot(): array
    {
        return PilotCourseTotals::query()
            ->orderBy('user_id')
            ->orderBy('course_id')
            ->get()
            ->mapWithKeys(fn (PilotCourseTotals $totals): array => [
                sprintf('%d:%d', $totals->user_id, $totals->course_id) => [
                    'points' => $totals->points,
                    'stars' => $totals->stars,
                    'completed' => $totals->completed,
                    'finished_at' => $totals->finished_at?->toIso8601String(),
                ],
            ])
            ->all();
    }
}
