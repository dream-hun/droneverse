<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Enums\ChallengeStatus;
use App\Models\UserChallengeProgress;

/**
 * A graded simulator run, as the pilot is finally shown it.
 *
 * The merged standing travels back with the result because this run may not
 * have been the pilot's best: `result` is how *this* flight scored, `progress`
 * is where the pilot now stands on the mission.
 */
final class ChallengeAttemptResource
{
    /**
     * @param  array{completed: bool, score: int, stars: int, objectivesHit: int, objectivesTotal: int, waypointsHit: int, waypointsTotal: int, collisions: int, landed: bool, elapsedSeconds: float, timedOut: bool, photosTaken: int, photoTargetsHit: int, photoTargetsTotal: int, photosMissing: int, washRequired: bool, washed: bool}  $result  as returned by \App\Actions\GradeSimulatorRun
     * @return array{result: array{completed: bool, score: int, stars: int, objectivesHit: int, objectivesTotal: int, waypointsHit: int, waypointsTotal: int, collisions: int, landed: bool, elapsedSeconds: float, timedOut: bool, photosTaken: int, photoTargetsHit: int, photoTargetsTotal: int, photosMissing: int, washRequired: bool, washed: bool}, progress: array{status: ChallengeStatus, bestScore: int, stars: int, attempts: int}}
     */
    public static function one(array $result, UserChallengeProgress $progress): array
    {
        return [
            'result' => $result,
            'progress' => [
                'status' => $progress->status,
                'bestScore' => $progress->best_score,
                'stars' => $progress->stars,
                'attempts' => $progress->attempts,
            ],
        ];
    }
}
