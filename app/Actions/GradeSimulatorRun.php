<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\Challenge;

/**
 * Scores a reconstructed run.
 *
 * This is the authority on what a run was worth. The browser grades the same
 * telemetry with the same policy so the pilot sees a result the instant they
 * land, but that copy is a preview — what gets stored, and what reaches the
 * leaderboard, is decided here.
 *
 * The policy is mirrored in resources/js/lib/simulator/grader.ts. The two
 * have to agree, or pilots watch their score change after the fact; the
 * weights below are the shared contract, and ChallengeTest pins the cases
 * that would drift first.
 */
final class GradeSimulatorRun
{
    /**
     * Scoring policy out of 100, before scaling to the challenge's max score:
     * mission objectives dominate, landing and finishing in time round it
     * out, and each collision costs a flat penalty on collision-sensitive
     * challenges.
     */
    private const int OBJECTIVE_WEIGHT = 70;

    private const int LANDING_WEIGHT = 20;

    private const int TIME_WEIGHT = 10;

    private const int COLLISION_PENALTY = 10;

    /** Finishing under this fraction of the time limit earns the speed star. */
    private const float FAST_FINISH_RATIO = 0.75;

    private const int MAX_STARS = 3;

    /**
     * @param  array{waypointsHit: int, waypointsTotal: int, collisions: int, maxAltitude: float, landed: bool, elapsedSeconds: float, timedOut: bool, photosTaken: int, photoTargetsHit: int, photoTargetsTotal: int, photosMissing: int, washRequired: bool, washed: bool}  $telemetry
     * @return array{completed: bool, score: int, stars: int, waypointsHit: int, waypointsTotal: int, collisions: int, landed: bool, elapsedSeconds: float, timedOut: bool, photosTaken: int, photoTargetsHit: int, photoTargetsTotal: int, photosMissing: int, washRequired: bool, washed: bool}
     */
    public function handle(array $telemetry, Challenge $challenge): array
    {
        $criteria = $challenge->success_criteria;
        $minPhotos = (int) ($criteria['min_photos'] ?? 0);

        // Objectives generalize the waypoint ratio: each photo target, the
        // min-photo quota, and the wash pass all count like one waypoint, so
        // city missions grade on the same curve as plain navigation runs.
        $objectivesTotal = $telemetry['waypointsTotal']
            + $telemetry['photoTargetsTotal']
            + ($minPhotos > 0 ? 1 : 0)
            + ($telemetry['washRequired'] ? 1 : 0);

        $objectivesHit = $telemetry['waypointsHit']
            + $telemetry['photoTargetsHit']
            + ($minPhotos > 0 && $telemetry['photosMissing'] === 0 ? 1 : 0)
            + ($telemetry['washRequired'] && $telemetry['washed'] ? 1 : 0);

        $objectivesRatio = $objectivesTotal > 0
            ? $objectivesHit / $objectivesTotal
            : 1.0;

        $minAltitude = $criteria['min_altitude'] ?? null;
        $meetsMinAltitude = $minAltitude === null
            || $telemetry['maxAltitude'] >= (float) $minAltitude;
        $meetsLanding = ($criteria['landing_required'] ?? false) === false
            || $telemetry['landed'];
        $withinTime = ! $telemetry['timedOut'];

        $completed = $objectivesRatio >= 1.0
            && $meetsMinAltitude
            && $meetsLanding
            && $withinTime;

        $score = $objectivesRatio * self::OBJECTIVE_WEIGHT
            + ($meetsLanding ? self::LANDING_WEIGHT : 0)
            + ($withinTime ? self::TIME_WEIGHT : 0);

        if (($criteria['avoid_collisions'] ?? false) === true) {
            $score = max(0, $score - $telemetry['collisions'] * self::COLLISION_PENALTY);
        }

        $score = (int) round(
            max(0, min(100, $score)) * ($challenge->max_score / 100)
        );

        return [
            'completed' => $completed,
            'score' => $score,
            'stars' => $this->stars($telemetry, $completed, (float) $criteria['max_time_seconds']),
            'waypointsHit' => $telemetry['waypointsHit'],
            'waypointsTotal' => $telemetry['waypointsTotal'],
            'collisions' => $telemetry['collisions'],
            'landed' => $telemetry['landed'],
            'elapsedSeconds' => $telemetry['elapsedSeconds'],
            'timedOut' => $telemetry['timedOut'],
            'photosTaken' => $telemetry['photosTaken'],
            'photoTargetsHit' => $telemetry['photoTargetsHit'],
            'photoTargetsTotal' => $telemetry['photoTargetsTotal'],
            'photosMissing' => $telemetry['photosMissing'],
            'washRequired' => $telemetry['washRequired'],
            'washed' => $telemetry['washed'],
        ];
    }

    /**
     * Stars are earned on top of completion: one for finishing, one for a
     * clean flight, one for beating the clock comfortably.
     *
     * @param  array{collisions: int, elapsedSeconds: float}  $telemetry
     */
    private function stars(array $telemetry, bool $completed, float $maxSeconds): int
    {
        if (! $completed) {
            return 0;
        }

        $stars = 1;

        if ($telemetry['collisions'] === 0) {
            $stars++;
        }

        if ($telemetry['elapsedSeconds'] <= $maxSeconds * self::FAST_FINISH_RATIO) {
            $stars++;
        }

        return min(self::MAX_STARS, $stars);
    }
}
