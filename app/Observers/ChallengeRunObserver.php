<?php

declare(strict_types=1);

namespace App\Observers;

use App\Actions\RollUpChallengeRun;
use App\Models\ChallengeRun;
use Throwable;

/**
 * Keeps {@see \App\Models\PilotMissionStats} in step with the runs.
 *
 * An observer rather than a line in {@see \App\Actions\RecordChallengeAttempt}
 * because the rollup is a property of the run table, not of one path into it.
 * A derived total that a caller has to remember to update is wrong the first
 * time anybody writes a run without knowing it exists — and the test suite,
 * which builds run histories out of factories, is already that caller.
 *
 * Runs are never revised and never deleted one at a time, so there is nothing
 * to observe but their creation. A cascade — deleting a pilot, retiring a
 * mission — takes the stats rows with the runs at the database level, which
 * fires no model events and needs none.
 */
final readonly class ChallengeRunObserver
{
    public function __construct(private RollUpChallengeRun $rollUp) {}

    /**
     * @throws Throwable
     */
    public function created(ChallengeRun $run): void
    {
        $this->rollUp->handle($run);
    }
}
