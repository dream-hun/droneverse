<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\ChallengeStatus;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Recompute the read-model rollups from the tables they are derived from.
 *
 * The rollups are a cache with a schema. Everything in them can be worked out
 * again from `challenge_runs` and `user_challenge_progress`, and this is where
 * that is written down — which is what makes it safe for
 * {@see RollUpChallengeRun} to keep its totals incrementally. Three callers:
 * the migration that backfills the tables for pilots who flew before they
 * existed, {@see \App\Observers\ChallengeObserver} when publishing a mission
 * changes which progress a course's totals are summed over, and the
 * `rollups:rebuild` command for when someone has been in the database by
 * hand.
 *
 * Written as `insert ... select` rather than as a loop over rows. A rebuild
 * covers every pilot at once and the aggregate is exactly the one the read
 * models used to run inline, so there is no reason to pull it through PHP —
 * and doing so would mean holding a result cursor open across the writes that
 * consume it.
 *
 * Not safe to run against live traffic. It clears the tables and rebuilds
 * them, so a run landing mid-rebuild can be counted into a row that is then
 * replaced by a total taken before it. That is the correct trade for an
 * operation whose whole job is to be authoritative, and it is why the
 * incremental path exists.
 */
final readonly class RebuildRollups
{
    /**
     * Rebuild both rollups, for every pilot.
     *
     * @throws Throwable
     */
    public function handle(): void
    {
        $this->missionStats();
        $this->courseTotals();
    }

    /**
     * Rebuild {@see \App\Models\PilotMissionStats} from every graded run.
     *
     * @throws Throwable
     */
    public function missionStats(): void
    {
        DB::transaction(function (): void {
            DB::table('pilot_mission_stats')->delete();

            DB::table('pilot_mission_stats')->insertUsing([
                'user_id',
                'challenge_id',
                'runs',
                'clean_runs',
                'cleared',
                'best_score',
                'collisions_total',
                'elapsed_seconds_total',
                'attempts_to_clear',
                'last_run_id',
                'created_at',
                'updated_at',
            ], $this->missionStatsQuery());
        });
    }

    /**
     * Rebuild {@see \App\Models\PilotCourseTotals}, optionally for one course.
     *
     * Scoped rebuilds are what publishing a mission needs: the totals are
     * summed over published missions only, so the courses on either side of
     * the change have to be recomputed, and nothing else has moved.
     *
     * @throws Throwable
     */
    public function courseTotals(?int $courseId = null): void
    {
        DB::transaction(function () use ($courseId): void {
            DB::table('pilot_course_totals')
                ->when($courseId !== null, fn (Builder $rows): Builder => $rows->where('course_id', $courseId))
                ->delete();

            DB::table('pilot_course_totals')->insertUsing([
                'user_id',
                'course_id',
                'points',
                'stars',
                'completed',
                'finished_at',
                'created_at',
                'updated_at',
            ], $this->courseTotalsQuery($courseId));
        });
    }

    /**
     * One row per pilot per mission they have flown.
     *
     * `attempts_to_clear` is the only column that cannot be read off a plain
     * aggregate: it counts the runs up to and including the one that first
     * cleared the mission, so the first clear has to be found per pairing and
     * joined back in. It stays null for a mission the pilot has never
     * cleared, which is a different statement from zero — the outer `case`
     * is what turns a sum of nothing into that null.
     */
    private function missionStatsQuery(): Builder
    {
        $firstClears = DB::table('challenge_runs')
            ->where('completed', true)
            ->groupBy('user_id', 'challenge_id')
            ->selectRaw('user_id, challenge_id, min(id) as cleared_id');

        return DB::table('challenge_runs')
            ->leftJoinSub($firstClears, 'first_clears', function ($join): void {
                $join->on('first_clears.user_id', '=', 'challenge_runs.user_id')
                    ->on('first_clears.challenge_id', '=', 'challenge_runs.challenge_id');
            })
            ->groupBy('challenge_runs.user_id', 'challenge_runs.challenge_id')
            ->selectRaw(
                'challenge_runs.user_id, '
                .'challenge_runs.challenge_id, '
                .'count(*), '
                .'sum(case when challenge_runs.collisions = 0 then 1 else 0 end), '
                .'max(case when challenge_runs.completed = ? then 1 else 0 end), '
                .'coalesce(max(challenge_runs.score), 0), '
                .'coalesce(sum(challenge_runs.collisions), 0), '
                .'coalesce(sum(challenge_runs.elapsed_seconds), 0), '
                .'case when max(case when challenge_runs.completed = ? then 1 else 0 end) = 1 '
                .'then sum(case when challenge_runs.id <= first_clears.cleared_id then 1 else 0 end) end, '
                .'max(challenge_runs.id), '
                .'?, ?',
                [true, true, now(), now()],
            );
    }

    /**
     * One row per pilot per course they hold published progress in.
     *
     * A pilot whose only progress is on retired missions produces no row at
     * all, which is what keeps them off a board that is meant to be a record
     * of flying that still counts.
     */
    private function courseTotalsQuery(?int $courseId): Builder
    {
        return DB::table('user_challenge_progress')
            ->join('challenges', 'challenges.id', '=', 'user_challenge_progress.challenge_id')
            ->where('challenges.is_published', true)
            ->when($courseId !== null, fn (Builder $rows): Builder => $rows->where('challenges.course_id', $courseId))
            ->groupBy('user_challenge_progress.user_id', 'challenges.course_id')
            ->selectRaw(
                'user_challenge_progress.user_id, '
                .'challenges.course_id, '
                .'coalesce(sum(user_challenge_progress.best_score), 0), '
                .'coalesce(sum(user_challenge_progress.stars), 0), '
                .'count(case when user_challenge_progress.status = ? then 1 end), '
                .$this->latestCompletion().', '
                .'?, ?',
                [ChallengeStatus::Completed->value, now(), now()],
            );
    }

    /**
     * The pilot's last completion in the course, in a form that survives being
     * written by `insert ... select`.
     *
     * A pilot who has started missions in a course but finished none — most of
     * them, most of the time — groups to a null here, and on MySQL a null
     * `max()` over a `timestamp` column materialises as the zero date on its
     * way into the insert. `NO_ZERO_DATE` is in the default `sql_mode`, so it
     * then rejects the row and the whole rebuild fails. The same aggregate
     * read as a plain select is unaffected, which is why nothing else that
     * computes this column has the problem.
     *
     * Casting each value to `datetime` before aggregating leaves `max()` over
     * a type that has no zero, so the null stays a null. The cast has to be
     * inside the aggregate: applied to the result instead, it is handed the
     * zero date that has already been produced.
     *
     * Only MySQL gets it. SQLite gives type names it does not recognise
     * numeric affinity, so `as datetime` there would parse
     * `2026-08-14 09:15:30` down to the integer `2026` and silently corrupt
     * every finish time in the table.
     *
     * The two branches are both literals: the result is concatenated into a
     * `selectRaw()`, which only accepts a `literal-string` so that nothing
     * that came from outside can reach the SQL text.
     *
     * @return literal-string
     */
    private function latestCompletion(): string
    {
        $completedAt = 'user_challenge_progress.completed_at';

        return in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)
            ? "max(cast($completedAt as datetime))"
            : "max($completedAt)";
    }
}
