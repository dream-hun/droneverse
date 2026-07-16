import type { ActiveCommand, DroneCommand, Vector3 } from './commands';

/**
 * The drone is a real dynamic Rapier rigid body (mass, damping, collisions with
 * obstacles/gates/ground all physically resolved) but is *velocity-controlled*
 * rather than torque-controlled — the same abstraction a real flight controller
 * gives a pilot. This keeps challenge grading tolerant of minor physics noise
 * while still letting the drone be knocked around by a collision.
 */

export const MAX_LINEAR_SPEED = 4; // meters/second
export const MAX_ANGULAR_SPEED = Math.PI; // radians/second
export const POSITION_EPSILON = 0.15; // meters
export const YAW_EPSILON = 0.03; // radians
export const REST_HEIGHT = 0.15; // meters, drone height when landed
export const DEFAULT_TAKEOFF_ALTITUDE = 1.5;
export const APPROACH_GAIN = 2;
export const ANGULAR_GAIN = 4;

export function forwardVector(yaw: number): { x: number; z: number } {
    return { x: -Math.sin(yaw), z: -Math.cos(yaw) };
}

function normalizeAngle(angle: number): number {
    let normalized = angle % (Math.PI * 2);

    if (normalized > Math.PI) {
        normalized -= Math.PI * 2;
    } else if (normalized < -Math.PI) {
        normalized += Math.PI * 2;
    }

    return normalized;
}

/** Called once when a command becomes active: resolves it into a concrete target. */
export function beginCommand(
    command: DroneCommand,
    position: Vector3,
    yaw: number,
): Omit<ActiveCommand, 'resolve'> {
    const base = {
        command,
        elapsed: 0,
        startPosition: position,
        startYaw: yaw,
    };

    switch (command.type) {
        case 'takeoff':
            return {
                ...base,
                targetPosition: {
                    ...position,
                    y: command.altitude ?? DEFAULT_TAKEOFF_ALTITUDE,
                },
            };
        case 'land':
            return { ...base, targetPosition: { ...position, y: REST_HEIGHT } };
        case 'setAltitude':
            return {
                ...base,
                targetPosition: { ...position, y: command.altitude },
            };
        case 'moveForward': {
            const forward = forwardVector(yaw);

            return {
                ...base,
                targetPosition: {
                    x: position.x + forward.x * command.distance,
                    y: position.y,
                    z: position.z + forward.z * command.distance,
                },
            };
        }
        case 'moveTo':
            return {
                ...base,
                targetPosition: { x: command.x, y: command.y, z: command.z },
            };
        case 'turn':
            return {
                ...base,
                targetYaw: yaw - (command.degrees * Math.PI) / 180,
            };
        case 'hover':
            return {
                ...base,
                targetPosition: position,
                hoverSeconds: command.seconds,
            };
        default:
            // Instant sensor queries (getPosition/getHeading/getAltitude/getDistanceAhead)
            // resolve on the same frame they're issued, so they have no target.
            return base;
    }
}

export type ControlStep = {
    linvel: Vector3;
    angvel: number;
    done: boolean;
};

/** Computes the velocity to command this frame, and whether the active command has completed. */
export function computeControlStep(
    active: ActiveCommand,
    position: Vector3,
    yaw: number,
    dt: number,
): ControlStep {
    if (active.command.type === 'hover') {
        const done = active.elapsed + dt >= (active.hoverSeconds ?? 0);

        return { linvel: { x: 0, y: 0, z: 0 }, angvel: 0, done };
    }

    if (active.command.type === 'turn' && active.targetYaw !== undefined) {
        const diff = normalizeAngle(active.targetYaw - yaw);

        if (Math.abs(diff) < YAW_EPSILON) {
            return { linvel: { x: 0, y: 0, z: 0 }, angvel: 0, done: true };
        }

        const angvel =
            Math.sign(diff) *
            Math.min(MAX_ANGULAR_SPEED, Math.abs(diff) * ANGULAR_GAIN);

        return { linvel: { x: 0, y: 0, z: 0 }, angvel, done: false };
    }

    if (active.targetPosition) {
        const dx = active.targetPosition.x - position.x;
        const dy = active.targetPosition.y - position.y;
        const dz = active.targetPosition.z - position.z;
        const distance = Math.sqrt(dx * dx + dy * dy + dz * dz);

        if (distance < POSITION_EPSILON) {
            return { linvel: { x: 0, y: 0, z: 0 }, angvel: 0, done: true };
        }

        const speed = Math.min(MAX_LINEAR_SPEED, distance * APPROACH_GAIN);
        const scale = speed / distance;

        return {
            linvel: { x: dx * scale, y: dy * scale, z: dz * scale },
            angvel: 0,
            done: false,
        };
    }

    // Instant sensor queries: nothing to move, already "done" the frame they start.
    return { linvel: { x: 0, y: 0, z: 0 }, angvel: 0, done: true };
}
