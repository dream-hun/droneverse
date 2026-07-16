import type { RunResult, SuccessCriteria } from '@/types/simulator';
import type { RunTelemetry } from './commands';

/**
 * Scoring policy, out of 100 before scaling to the challenge's max score:
 * waypoints dominate, landing and finishing in time round it out, and each
 * collision costs a flat penalty on collision-sensitive challenges.
 */
const WAYPOINT_WEIGHT = 70;
const LANDING_WEIGHT = 20;
const TIME_WEIGHT = 10;
const COLLISION_PENALTY = 10;

/** Finishing under this fraction of the time limit earns the speed star. */
const FAST_FINISH_RATIO = 0.75;

/**
 * Pure scoring function: tolerance-based (waypoint radii, collision counts,
 * time limits) so it stays robust to the minor non-determinism of real physics.
 */
export function gradeRun(
    telemetry: RunTelemetry,
    criteria: SuccessCriteria,
    maxScore: number,
): RunResult {
    const waypointsRatio =
        telemetry.waypointsTotal > 0
            ? telemetry.waypointsHit / telemetry.waypointsTotal
            : 1;
    const meetsMinAltitude = criteria.min_altitude
        ? telemetry.maxAltitude >= criteria.min_altitude
        : true;
    const meetsLanding = criteria.landing_required ? telemetry.landed : true;
    const withinTime = !telemetry.timedOut;

    const completed =
        waypointsRatio === 1 && meetsMinAltitude && meetsLanding && withinTime;

    let score =
        waypointsRatio * WAYPOINT_WEIGHT +
        (meetsLanding ? LANDING_WEIGHT : 0) +
        (withinTime ? TIME_WEIGHT : 0);

    if (criteria.avoid_collisions) {
        score = Math.max(0, score - telemetry.collisions * COLLISION_PENALTY);
    }

    score = Math.round(Math.min(100, Math.max(0, score)) * (maxScore / 100));

    let stars = 0;

    if (completed) {
        stars = 1;

        if (telemetry.collisions === 0) {
            stars += 1;
        }

        if (
            telemetry.elapsedSeconds <=
            criteria.max_time_seconds * FAST_FINISH_RATIO
        ) {
            stars += 1;
        }
    }

    return {
        completed,
        score,
        stars,
        waypointsHit: telemetry.waypointsHit,
        waypointsTotal: telemetry.waypointsTotal,
        collisions: telemetry.collisions,
        landed: telemetry.landed,
        elapsedSeconds: telemetry.elapsedSeconds,
        timedOut: telemetry.timedOut,
    };
}
