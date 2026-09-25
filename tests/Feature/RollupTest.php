<?php

declare(strict_types=1);

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
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

/*
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
test('counting runs as they land agrees with rebuilding from them', function (): void {
    flyAVariedHistory();

    $incremental = missionStatsSnapshot();

    resolve(RebuildRollups::class)->missionStats();

    expect(missionStatsSnapshot())->toBe($incremental, 'the totals kept as runs landed are not the totals the runs add up to');
});

test('recomputing course totals agrees with rebuilding them', function (): void {
    flyAVariedHistory();

    $incremental = courseTotalsSnapshot();

    resolve(RebuildRollups::class)->courseTotals();

    expect(courseTotalsSnapshot())
        ->toBe($incremental, 'the totals kept as progress moved are not the totals the progress adds up to');
});

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
test('a rebuild keeps finish times a pilot has and nulls the ones they do not', function (): void {
    $pilot = User::factory()->create();

    $unfinishedCourse = Course::factory()->create();
    rollupProgress($pilot, Challenge::factory()->for($unfinishedCourse)->create(['max_score' => 100]), points: 30);

    $finishedCourse = Course::factory()->create();
    $cleared = Challenge::factory()->for($finishedCourse)->create(['max_score' => 100]);
    rollupProgress($pilot, $cleared, points: 90, completed: true);

    $completedAt = UserChallengeProgress::query()
        ->where('user_id', $pilot->id)
        ->where('challenge_id', $cleared->id)
        ->sole()
        ->completed_at;

    $this->assertNotNull($completedAt);

    resolve(RebuildRollups::class)->courseTotals();

    expect(finishedAtFor($pilot, $unfinishedCourse))
        ->toBeNull('a course the pilot has finished nothing in was given a finish time');

    expect(finishedAtFor($pilot, $finishedCourse)?->toIso8601String())
        ->toBe($completedAt->toIso8601String(), 'the rebuild did not carry the completion time through intact');
});

test('attempts to clear freezes at the run that cleared the mission', function (): void {
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

    expect($stats->attempts_to_clear)->toBe(3);
    expect($stats->runs)->toBe(7);
    expect($stats->cleared)->toBeTrue();
});

test('a mission never cleared records no attempts to clear', function (): void {
    // Null rather than zero, which is what keeps an uncleared mission out
    // of the mean instead of dragging it to the floor.
    $user = User::factory()->create();
    $challenge = Challenge::factory()->create();

    ChallengeRun::factory()->count(3)->create([
        'user_id' => $user->id,
        'challenge_id' => $challenge->id,
    ]);

    expect(PilotMissionStats::query()->where('user_id', $user->id)->where('challenge_id', $challenge->id)->value('attempts_to_clear'))
        ->toBeNull();
});

test('a rebuild corrects a rollup that has gone wrong', function (): void {
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

    expect(Artisan::call('rollups:rebuild'))->toBe(Command::SUCCESS);

    $stats = PilotMissionStats::query()->where('user_id', $user->id)->sole();

    expect($stats->runs)->toBe(3);
    expect($stats->best_score)->not->toBe(999);
});

test('a rebuild drops a rollup row whose runs are gone', function (): void {
    // A rebuild replaces the tables rather than patching them, so a row
    // with nothing behind it does not survive one.
    $orphan = PilotMissionStats::factory()->create(['runs' => 12]);

    resolve(RebuildRollups::class)->missionStats();

    $this->assertDatabaseMissing('pilot_mission_stats', ['id' => $orphan->id]);
});

test('retiring a mission takes its points off the board', function (): void {
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

    rollupProgress($pilot, $kept, points: 30);
    rollupProgress($pilot, $retired, points: 70);

    expect(pointsFor($pilot, $course))->toBe(100);

    $retired->update(['is_published' => false]);

    expect(pointsFor($pilot, $course))->toBe(30, 'a retired mission kept its points on the board');
});

test('publishing a mission puts its points back', function (): void {
    $course = Course::factory()->create();
    $challenge = Challenge::factory()->for($course)->unpublished()->create(['max_score' => 100]);
    $pilot = User::factory()->create();

    rollupProgress($pilot, $challenge, points: 55);

    expect(pointsFor($pilot, $course))->toBeNull();

    $challenge->update(['is_published' => true]);

    expect(pointsFor($pilot, $course))->toBe(55);
});

test('a pilot with nothing playable left has no row at all', function (): void {
    /*
     * A row of zeroes and no row are different things to the board. The
     * standings list pilots who have flown something that still counts,
     * so a pilot whose only mission was retired has to disappear rather
     * than appear on nil points.
     */
    $course = Course::factory()->create();
    $challenge = Challenge::factory()->for($course)->create(['max_score' => 100]);
    $pilot = User::factory()->create();

    rollupProgress($pilot, $challenge, points: 40);

    expect(resolve(Leaderboard::class)->rankedPilotCount())->toBe(1);

    $challenge->update(['is_published' => false]);

    $this->assertDatabaseMissing('pilot_course_totals', [
        'user_id' => $pilot->id,
        'course_id' => $course->id,
    ]);
    expect(resolve(Leaderboard::class)->rankedPilotCount())->toBe(0);
});

test('moving a mission between courses rebuilds both of them', function (): void {
    $from = Course::factory()->create();
    $to = Course::factory()->create();
    $challenge = Challenge::factory()->for($from)->create(['max_score' => 100]);
    $pilot = User::factory()->create();

    rollupProgress($pilot, $challenge, points: 60);

    expect(pointsFor($pilot, $from))->toBe(60);

    $challenge->update(['course_id' => $to->id]);

    expect(pointsFor($pilot, $from))->toBeNull('the old course kept points it no longer holds');
    expect(pointsFor($pilot, $to))->toBe(60);
});

test('the read models never touch the raw tables for a total', function (): void {
    /*
     * The point of the rollups. Every slice but the curve is a total, and
     * a total that still scanned `challenge_runs` would cost more to read
     * the more a pilot had flown — which is the growth these tables were
     * added to stop.
     */
    $user = flyAVariedHistory();
    $flightLog = resolve(FlightLog::class);

    DB::enableQueryLog();
    $flightLog->summaryFor($user);
    $flightLog->weakSpots($user);
    $flightLog->flownMissions($user);

    $queries = array_filter(array_column(DB::getRawQueryLog(), 'raw_query'), is_string(...));
    DB::disableQueryLog();

    $overRuns = array_values(array_filter(
        $queries,
        fn (string $sql): bool => str_contains($sql, 'challenge_runs'),
    ));

    expect($overRuns)->toBe([], 'a total was still aggregated over the run table');
});

/**
 * A pilot with the kinds of history the rollup columns distinguish.
 *
 * Two courses, four missions: one cleared after a couple of misses, one
 * cleared on the first run, one never cleared, and one flown once. Runs
 * are created through the factory, which is the same path the recording
 * action takes into the observer that maintains the rollup.
 */
function flyAVariedHistory(): User
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

    rollupProgress($pilot, $struggled, points: 80, completed: true);
    rollupProgress($pilot, $straightaway, points: 95, completed: true);
    rollupProgress($pilot, $stuck, points: 30);
    rollupProgress($pilot, $touched, points: 10);

    // A second pilot, so the cohort columns are not all populations of
    // one and a rebuild has more than a single row to get right.
    $rival = User::factory()->create();
    ChallengeRun::factory()->count(3)->completed()->scoring(70)->create([
        'user_id' => $rival->id,
        'challenge_id' => $stuck->id,
    ]);
    rollupProgress($rival, $stuck, points: 70, completed: true);

    return $pilot;
}

function rollupProgress(User $user, Challenge $challenge, int $points, bool $completed = false): void
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
function pointsFor(User $user, Course $course): ?int
{
    return PilotCourseTotals::query()
        ->where('user_id', $user->id)
        ->where('course_id', $course->id)
        ->first()
        ?->points;
}

/**
 * The stored finish time for one pilot in one course.
 */
function finishedAtFor(User $user, Course $course): ?CarbonInterface
{
    return PilotCourseTotals::query()
        ->where('user_id', $user->id)
        ->where('course_id', $course->id)
        ->first()
        ?->finished_at;
}

/**
 * Every mission-stats row, in a form two runs of the suite can compare.
 *
 * Keyed and ordered by the pairing rather than by id, because a rebuild
 * writes new rows and their ids are not part of what the rollup means.
 *
 * @return array<string, array<string, mixed>>
 */
function missionStatsSnapshot(): array
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
function courseTotalsSnapshot(): array
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
