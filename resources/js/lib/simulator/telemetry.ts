import type { WaypointConfig } from '@/types/simulator';
import type { SimulationBridge, Vector3 } from './commands';

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
