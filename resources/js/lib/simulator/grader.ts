import type { RunTelemetry } from './commands';
import type { RunResult, SuccessCriteria } from '@/types/simulator';

/**
 * Scoring policy, out of 100 before scaling to the challenge's max score:
 * mission objectives dominate, landing and finishing in time round it out,
 * and each collision costs a flat penalty on collision-sensitive challenges.
 */
const OBJECTIVE_WEIGHT = 70;
const LANDING_WEIGHT = 20;
const TIME_WEIGHT = 10;
const COLLISION_PENALTY = 10;

/** Finishing under this fraction of the time limit earns the speed star. */
const FAST_FINISH_RATIO = 0.75;

/**
 * Pure scoring function: tolerance-based (waypoint radii, collision counts,
 * time limits) so it stays robust to the minor non-determinism of real physics.
 *
 * Objectives generalize the waypoint ratio: each photo target, the
 * min-photo quota, and the wash pass all count like one waypoint, so city
 * missions with cameras and the wash tunnel grade on the same curve as
 * plain navigation runs.
 */
export function gradeRun(
    telemetry: RunTelemetry,
    criteria: SuccessCriteria,
    maxScore: number,
): RunResult {
    const photoTargets = criteria.photo_targets ?? [];
    const photoTargetsHit = photoTargets.filter((target) =>
        telemetry.photoPositions.some(
            (photo) =>
                Math.hypot(photo.x - target.x, photo.z - target.z) <=
                target.radius,
        ),
    ).length;

    const photosTaken = telemetry.photoPositions.length;
    const minPhotos = criteria.min_photos ?? 0;
    const photosMissing = Math.max(0, minPhotos - photosTaken);

    const washRequired = criteria.wash_required === true;
    const washed = telemetry.washEntryHit && telemetry.washExitHit;

    const objectivesTotal =
        telemetry.waypointsTotal +
        photoTargets.length +
        (minPhotos > 0 ? 1 : 0) +
        (washRequired ? 1 : 0);
    const objectivesHit =
        telemetry.waypointsHit +
        photoTargetsHit +
        (minPhotos > 0 && photosMissing === 0 ? 1 : 0) +
        (washRequired && washed ? 1 : 0);
    const objectivesRatio =
        objectivesTotal > 0 ? objectivesHit / objectivesTotal : 1;

    const meetsMinAltitude = criteria.min_altitude
        ? telemetry.maxAltitude >= criteria.min_altitude
        : true;
    const meetsLanding = criteria.landing_required ? telemetry.landed : true;
    const withinTime = !telemetry.timedOut;

    const completed =
        objectivesRatio === 1 && meetsMinAltitude && meetsLanding && withinTime;

    let score =
        objectivesRatio * OBJECTIVE_WEIGHT +
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
        objectivesHit,
        objectivesTotal,
        waypointsHit: telemetry.waypointsHit,
        waypointsTotal: telemetry.waypointsTotal,
        collisions: telemetry.collisions,
        landed: telemetry.landed,
        elapsedSeconds: telemetry.elapsedSeconds,
        timedOut: telemetry.timedOut,
        photosTaken,
        photoTargetsHit,
        photoTargetsTotal: photoTargets.length,
        photosMissing,
        washRequired,
        washed,
    };
}
