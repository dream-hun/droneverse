import type { EnvironmentConfig } from '@/types/simulator';
import type { ScanContact, Vector3 } from './commands';
import { classifyObstacle } from './obstacles';

export const DEFAULT_SCAN_RANGE = 15; // meters
export const MIN_SCAN_RANGE = 2;
export const MAX_SCAN_RANGE = 40;

/** Prop center heights used for scan reporting (matches the visuals). */
const PROP_CENTER_Y: Record<'car' | 'van' | 'tree', number> = {
    car: 0.7,
    van: 1.1,
    tree: 1.8,
};

const CARWASH_DEFAULT_HEIGHT = 4.2;

function contact(
    kind: string,
    label: string | null,
    target: Vector3,
    position: Vector3,
    yaw: number,
): ScanContact {
    const dx = target.x - position.x;
    const dy = target.y - position.y;
    const dz = target.z - position.z;

    // Absolute bearing of the target (compass convention: 0 at -Z, clockwise
    // positive), minus the drone's own compass heading, normalized to ±180.
    const absoluteBearing = Math.atan2(dx, -dz);
    const headingRad = -yaw;
    let relative = ((absoluteBearing - headingRad) * 180) / Math.PI;
    relative = ((relative + 540) % 360) - 180;

    return {
        kind,
        label,
        x: Math.round(target.x * 10) / 10,
        y: Math.round(target.y * 10) / 10,
        z: Math.round(target.z * 10) / 10,
        distance: Math.round(Math.hypot(dx, dy, dz) * 10) / 10,
        bearingDeg: Math.round(relative),
    };
}

/**
 * The simulated object-detection suite: reports every physical object in
 * the environment config within `range` meters of the drone, nearest first.
 * Purely geometric — computed from the same config that spawns the scene,
 * so the sensor never disagrees with what the pilot sees.
 */
export function scanEnvironment(
    environment: EnvironmentConfig,
    position: Vector3,
    yaw: number,
    range?: number,
): ScanContact[] {
    const radius = Math.min(
        MAX_SCAN_RANGE,
        Math.max(MIN_SCAN_RANGE, range ?? DEFAULT_SCAN_RANGE),
    );
    const contacts: ScanContact[] = [];

    for (const obstacle of environment.obstacles) {
        contacts.push(
            contact(
                classifyObstacle(obstacle),
                obstacle.label ?? null,
                { x: obstacle.x, y: obstacle.y, z: obstacle.z },
                position,
                yaw,
            ),
        );
    }

    for (const prop of environment.props ?? []) {
        contacts.push(
            contact(
                prop.kind,
                prop.label ?? null,
                { x: prop.x, y: PROP_CENTER_Y[prop.kind], z: prop.z },
                position,
                yaw,
            ),
        );
    }

    const carwash = environment.carwash;

    if (carwash) {
        contacts.push(
            contact(
                'carwash',
                carwash.label ?? null,
                {
                    x: carwash.x,
                    y: (carwash.height ?? CARWASH_DEFAULT_HEIGHT) / 2,
                    z: carwash.z,
                },
                position,
                yaw,
            ),
        );
    }

    return contacts
        .filter((entry) => entry.distance <= radius)
        .sort((a, b) => a.distance - b.distance);
}
