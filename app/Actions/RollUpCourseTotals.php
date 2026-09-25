<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\ChallengeStatus;
use App\Models\PilotCourseTotals;
use App\Queries\Leaderboard;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Recompute what one pilot's progress in one course adds up to.
 *
 * The write half of {@see PilotCourseTotals}, and the thing that
 * lets the leaderboard rank pilots without grouping every progress row in the
 * schema.
 *
 * Recomputed rather than incremented, which is the opposite choice from
 * {@see RollUpChallengeRun}. A run's effect on a standing is not a delta:
 * best score and stars are monotonic merges, so a run that beat nothing
 * changes nothing, and a run that beat something changes the total by an
 * amount only the previous row knew. Reading the handful of progress rows
 * behind the total is both simpler and incapable of drifting — the whole
 * source is a pilot's missions within one course.
 *
 * Only published missions are counted, which is the one live predicate this
 * rollup swallows. `courses.is_published` is left as a join on the read side,
 * so pulling a whole course still takes effect immediately; per-mission
 * publication cannot be, so {@see \App\Observers\ChallengeObserver} rebuilds
 * the affected courses when it changes.
 *
 * Retiring the cached board is part of the same job, for the same reason the
 * write is: see {@see self::retireBoard()}.
 */
final readonly class RollUpCourseTotals
{
    /**
     * How many times the row is recreated before the recompute gives up.
     *
     * The row can be deleted between this action creating it and locking it,
     * and each cause resolves on a retry: a competing recompute that found
     * nothing playable holds its lock until it commits, and so does the
     * `delete`-then-`insert ... select` in {@see RebuildRollups::courseTotals()}.
     * Once either has committed, the row either exists again or this pilot
     * genuinely has nothing left in the course, and one more pass settles
     * which. More than a couple of attempts would mean something is deleting
     * in a loop, which retrying cannot fix.
     */
    private const int LOCK_ATTEMPTS = 3;

    public function __construct(private Leaderboard $leaderboard) {}

    /**
     * @throws Throwable
     */
    public function handle(int $userId, int $courseId): void
    {
        DB::transaction(function () use ($userId, $courseId): void {
            $totals = $this->lockedTotals($userId, $courseId);

            if (! $totals instanceof PilotCourseTotals) {
                /*
                 * Something is deleting this row faster than it can be
                 * recreated, which retrying will not settle. Leaving the
                 * rollup untouched is the safe end: it is derived data, and
                 * `rollups:rebuild` reconstructs it from the progress rows
                 * that are still the source of truth.
                 */
                return;
            }

            $row = fluent(DB::table('user_challenge_progress')
                ->join('challenges', 'challenges.id', '=', 'user_challenge_progress.challenge_id')
                ->where('user_challenge_progress.user_id', $userId)
                ->where('challenges.course_id', $courseId)
                ->where('challenges.is_published', true)
                ->selectRaw(
                    'count(*) as rows_counted, '
                    .'coalesce(sum(user_challenge_progress.best_score), 0) as points, '
                    .'coalesce(sum(user_challenge_progress.stars), 0) as stars, '
                    .'count(case when user_challenge_progress.status = ? then 1 end) as completed, '
                    .'max(user_challenge_progress.completed_at) as finished_at',
                    [ChallengeStatus::Completed->value],
                )
                ->first());

            /*
             * No playable progress left means no place on the board. The
             * board is a record of simulator time, so a pilot whose only
             * flights were on missions that have since been retired drops off
             * it rather than appearing on nil points — which is what a row of
             * zeroes would put them there on.
             */
            if ($row->integer('rows_counted') === 0) {
                $totals->delete();
                $this->retireBoard($courseId);

                return;
            }

            $totals->points = $row->integer('points');
            $totals->stars = $row->integer('stars');
            $totals->completed = $row->integer('completed');
            $totals->finished_at = $row->date('finished_at');
            $totals->save();

            $this->retireBoard($courseId);
        });
    }

    /**
     * Retire the cached boards this recompute has moved, once it is real.
     *
     * The invalidation belongs here rather than with the callers for the same
     * reason the write does: {@see RecordChallengeAttempt} is not
     * the only thing that moves a pilot's totals — {@see SelectMissionDrone}
     * writes a progress row the first time a pilot picks an airframe, which
     * puts them on the board — and a cache that only stays right while every
     * caller remembers to flush it is a cache that will be wrong.
     *
     * Deferred to the commit, which is the part that is easy to get wrong.
     * This action runs inside the caller's transaction, so flushing here and
     * now would bump the generation while the new totals are still invisible
     * to everyone else: a reader would miss, rebuild the board from the rows
     * as they stand *before* the commit, and cache that under the new
     * generation — leaving a stale board that nothing will retire until the
     * TTL expires. Exactly the failure the counter exists to prevent, caused
     * by invalidating too early rather than too late.
     */
    private function retireBoard(int $courseId): void
    {
        DB::afterCommit(fn () => $this->leaderboard->forgetCourse($courseId));
    }

    /**
     * The pilot's row for this course, created if absent and held for the
     * rest of the transaction.
     *
     * Created before it is read so that the read can lock it. Two of this
     * pilot's missions in the same course can be submitted at once, and each
     * transaction holds only its own mission's progress row — nothing there
     * stops both from computing the same total and the later write from being
     * the stale one. The upsert makes the row exist and takes the exclusive
     * row lock; `lockForUpdate` below then serializes the pair.
     *
     * `updated_at` is named as the update column rather than left to Eloquent
     * so the statement is a real upsert whatever the model does with
     * timestamps. Given a genuinely empty update list, `Query\Builder::upsert()`
     * runs a plain `insert()` — not the `insertOrIgnore` one might expect —
     * and the second run for a pairing would abort the whole attempt on the
     * unique key.
     *
     * Retried because the row can be deleted between the two statements, by a
     * competing recompute that found nothing playable or by a scoped
     * {@see RebuildRollups::courseTotals()}. Both hold their locks until they
     * commit, so a retry sees whichever answer they settled on.
     */
    private function lockedTotals(int $userId, int $courseId): ?PilotCourseTotals
    {
        for ($attempt = 0; $attempt < self::LOCK_ATTEMPTS; $attempt++) {
            PilotCourseTotals::query()->upsert(
                [[
                    'user_id' => $userId,
                    'course_id' => $courseId,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]],
                ['user_id', 'course_id'],
                ['updated_at'],
            );

            $totals = PilotCourseTotals::query()
                ->where('user_id', $userId)
                ->where('course_id', $courseId)
                ->lockForUpdate()
                ->first();

            if ($totals instanceof PilotCourseTotals) {
                return $totals;
            }
        }

        return null;
    }
}
