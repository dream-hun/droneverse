import type { WaypointConfig } from '@/types/simulator';
import type { SimulationBridge, Vector3 } from './commands';

/**
 * Seconds between flight-path samples sent to the server for grading.
 *
 * The server measures objectives against the segments between samples, not
 * the samples themselves, so this only has to be fine enough to trace the
 * shape of the flight — not to catch each waypoint crossing.
 */
const PATH_SAMPLE_INTERVAL = 0.05;

/** Matches the server's own ceiling; ~200s of flight at the sample rate. */
const MAX_PATH_SAMPLES = 4000;

/**
 * Record a point on the flight path, at a fixed rate regardless of frame
 * rate, so the submitted path is the same shape on a 144 Hz monitor as on a
 * struggling laptop.
 */
export function samplePath(bridge: SimulationBridge, position: Vector3): void {
    const path = bridge.telemetry.path;
    const elapsed = bridge.telemetry.elapsedSeconds;
    const last = path[path.length - 1];

    if (
        path.length >= MAX_PATH_SAMPLES ||
        (last !== undefined && elapsed - last.t < PATH_SAMPLE_INTERVAL)
    ) {
        return;
    }

    path.push({
        t: round(elapsed),
        x: round(position.x),
        y: round(position.y),
        z: round(position.z),
    });
}

/** Two decimals is centimetre precision — well past what grading resolves. */
function round(value: number): number {
    return Math.round(value * 100) / 100;
}

/**
 * Advance per-frame telemetry: elapsed time, peak altitude, and ordered
 * waypoint hits. Pure with respect to everything except the bridge it
 * mutates, so it can be unit-tested without a physics world or renderer.
 */
export function advanceTelemetry(
    bridge: SimulationBridge,
    position: Vector3,
    dt: number,
    waypoints: WaypointConfig[],
): void {
    bridge.telemetry.elapsedSeconds += dt;
    bridge.telemetry.maxAltitude = Math.max(
        bridge.telemetry.maxAltitude,
        position.y,
    );

    samplePath(bridge, position);

    const next = waypoints[bridge.nextWaypointIndex];

    if (!next) {
        return;
    }

    const dx = position.x - next.x;
    const dy = position.y - next.y;
    const dz = position.z - next.z;

    if (Math.sqrt(dx * dx + dy * dy + dz * dz) <= next.radius) {
        bridge.nextWaypointIndex += 1;
        bridge.telemetry.waypointsHit += 1;
    }
}

export function hasExceededTimeLimit(
    bridge: SimulationBridge,
    maxSeconds: number,
): boolean {
    return bridge.telemetry.elapsedSeconds >= maxSeconds;
}
