<?php

declare(strict_types=1);

namespace App\Observers;

use App\Actions\RollUpCourseTotals;
use App\Models\UserChallengeProgress;
use Throwable;

/**
 * Keeps {@see \App\Models\PilotCourseTotals} in step with the progress rows.
 *
 * The same argument as {@see ChallengeRunObserver}: the leaderboard's rollup
 * is a property of the progress table rather than of the one action that
 * currently writes to it, and a total that only stays right while every
 * caller remembers it is a total that will be wrong.
 *
 * `saved` rather than `updated`, because a first attempt creates the row and a
 * course with no totals for a pilot is exactly the state that keeps them off
 * the board. The recompute reads the course's progress rows back, so it does
 * not matter which columns changed or whether any did — it costs a small
 * aggregate over one pilot's missions in one course and it cannot drift.
 *
 * The challenge is read through the relation because a progress row names a
 * mission and the totals are keyed by course. It is one lookup, on a path
 * that has just done several writes, and the alternative is denormalising the
 * course onto every progress row so that this observer does not have to ask.
 *
 * `loadMissing` rather than the bare accessor, because the bare accessor is a
 * lazy load and AppServiceProvider turns those into exceptions outside
 * production. It does not throw today only by accident: Eloquent marks a model
 * as lazy-load-guarded only when more than one row came back from the query,
 * so the singly-hydrated progress row on the recording path slips through
 * while the same code reached from a collection — a backfill, an admin bulk
 * edit — would throw. Asking explicitly is allowed either way, and skips the
 * query when the caller has already loaded the relation.
 */
final readonly class UserChallengeProgressObserver
{
    public function __construct(private RollUpCourseTotals $rollUp) {}

    /**
     * @throws Throwable
     */
    public function saved(UserChallengeProgress $progress): void
    {
        $this->rollUpFor($progress);
    }

    /**
     * @throws Throwable
     */
    public function deleted(UserChallengeProgress $progress): void
    {
        $this->rollUpFor($progress);
    }

    /**
     * @throws Throwable
     */
    private function rollUpFor(UserChallengeProgress $progress): void
    {
        $courseId = $progress->loadMissing('challenge')->challenge?->course_id;

        if ($courseId === null) {
            // The mission is already gone, which means a cascade is in
            // progress and this row is on its way out with it.
            return;
        }

        $this->rollUp->handle($progress->user_id, $courseId);
    }
}
