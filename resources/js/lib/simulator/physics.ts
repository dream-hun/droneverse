import type { ActiveCommand, DroneCommand, Vector3 } from './commands';
import { clamp } from './math';
import type { DroneFlightSpec } from '@/types/drone';

/**
 * Flight model for a GPS multirotor in "Normal" mode.
 *
 * The drone is a real dynamic Rapier rigid body (mass, damping, collisions
 * with obstacles/ground all physically resolved) commanded through the same
 * abstraction a real flight controller gives a pilot: velocity setpoints.
 * Realism comes from how those setpoints are produced each frame:
 *
 * - Commanded velocity is slew-limited by horizontal/vertical acceleration
 *   caps, so every move is a trapezoidal accelerate-cruise-brake profile.
 * - Approach speed follows the braking curve v = sqrt(2·a·d), the profile
 *   real flight controllers use to arrive at a waypoint without overshoot.
 * - Takeoff spools the motors before lifting; landing flares to a slow
 *   final descent before touchdown.
 * - Yaw has its own rate/acceleration limits, and the drone position-holds
 *   while turning or hovering — exactly what GPS position-hold does.
 * - A zero-mean gust field perturbs the drone; the position loop constantly
 *   corrects, producing the micro-wander of a real drone holding position.
 *   (The mean wind component is modelled as already cancelled by the flight
 *   controller's wind estimator, which is why only gusts are applied.)
 *
 * The performance envelope itself is not in this file. Every cap and
 * acceleration a drone flies under is a field of the `DroneFlightSpec` the
 * server sends for the airframe the pilot chose, carried on the ControlState
 * for the length of a run. What stays here is the control law — the shape of
 * an approach, of a landing flare, of a position hold — which is the same
 * law on every airframe. A Vector and a Freighter fly differently because
 * they are given different numbers, not because they are flown by different
 * code.
 *
 * What remains as a module constant is what is genuinely not a property of
 * the drone: arrival tolerances (how close counts as arrived), the landing
 * procedure's flare gate, gravity, and the gains of the position loop.
 */

// Arrival tolerances: a command completes when the drone is inside the
// position window *and* has bled off its speed, so it settles instead of
// blasting through the target.
export const POSITION_EPSILON = 0.15; // meters
export const SETTLE_SPEED = 0.4; // m/s
export const YAW_EPSILON = 0.03; // radians
export const YAW_SETTLE_RATE = 0.35; // rad/s
export const LANDING_EPSILON = 0.05; // meters above rest height

export const DEFAULT_TAKEOFF_ALTITUDE = 1.5;
export const PHOTO_STABILIZE_SECONDS = 0.4; // hold steady before the shutter fires
export const FLARE_ALTITUDE = 0.7; // slow the descent below this height
export const FLARE_DESCENT_RATE = 0.5; // m/s final approach

// Visual attitude model: an airframe tilts into its acceleration, plus a
// steady tilt against aerodynamic drag while cruising. How far it will lean
// is the drone's own `maxTilt`; how much lean a given speed buys is this,
// and it is the air's property rather than the drone's.
export const GRAVITY = 9.81;
export const DRAG_TILT_COEFFICIENT = 0.55; // (m/s^2) of tilt per (m/s) of speed

const SETTLE_GAIN = 2.5; // proportional gain for the final approach and hold
const HOLD_SPEED_CAP = 1.6; // m/s while station-keeping
const BRAKING_MARGIN = 0.9; // track slightly inside the ideal braking curve

export function forwardVector(yaw: number): { x: number; z: number } {
    return { x: -Math.sin(yaw), z: -Math.cos(yaw) };
}

/** Yaw angle of a rotation quaternion constrained to the Y axis. */
export function quaternionYaw(rotation: { y: number; w: number }): number {
    return 2 * Math.atan2(rotation.y, rotation.w);
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

/**
 * Controller state persisted across frames for one run: the commanded
 * velocity being slewed, the yaw rate, the position-hold setpoint, the
 * pilot-adjustable cruise speed, and the envelope all of them are bounded by.
 *
 * The spec rides here rather than being threaded through every function
 * because it has exactly the lifetime this object does — one run, in one
 * airframe — and because every function that needs it already takes the
 * control state. Swapping drones between runs is therefore a new control
 * state, which is what `createControlState` is called with on each run
 * anyway, and there is no way to end up half-way between two airframes.
 */
export type ControlState = {
    velocity: Vector3;
    yawRate: number;
    cruiseSpeed: number;
    holdPoint: Vector3 | null;
    airborne: boolean;
    spec: DroneFlightSpec;
};

export function createControlState(spec: DroneFlightSpec): ControlState {
    return {
        velocity: { x: 0, y: 0, z: 0 },
        yawRate: 0,
        cruiseSpeed: spec.cruiseSpeed,
        holdPoint: null,
        airborne: false,
        spec,
    };
}

/**
 * Zero-mean gust disturbance built from incommensurate sine harmonics —
 * the classic light-turbulence approximation. `directionRad` is a compass
 * bearing (0 = -Z, the drone's initial forward) the wind blows toward.
 */
export type WindField = {
    meanSpeed: number;
    directionRad: number;
    gust: (seconds: number) => Vector3;
};

export function createWindField(
    meanSpeed: number = 1.8,
    directionRad: number = Math.random() * Math.PI * 2,
): WindField {
    const amplitude = Math.min(0.3, 0.1 + meanSpeed * 0.08);
    const along = { x: Math.sin(directionRad), z: -Math.cos(directionRad) };
    const cross = { x: -along.z, z: along.x };
    const phases = Array.from({ length: 4 }, () => Math.random() * Math.PI * 2);

    return {
        meanSpeed,
        directionRad,
        gust: (seconds: number) => {
            const surge =
                amplitude *
                (0.55 * Math.sin(2 * Math.PI * 0.11 * seconds + phases[0]) +
                    0.45 * Math.sin(2 * Math.PI * 0.29 * seconds + phases[1]));
            const sway =
                amplitude *
                0.4 *
                Math.sin(2 * Math.PI * 0.17 * seconds + phases[2]);
            const heave =
                amplitude *
                0.25 *
                Math.sin(2 * Math.PI * 0.23 * seconds + phases[3]);

            return {
                x: along.x * surge + cross.x * sway,
                y: heave,
                z: along.z * surge + cross.z * sway,
            };
        },
    };
}

/**
 * Called once when a command becomes active: resolves it into a concrete
 * target.
 *
 * Takes the spec because two commands resolve against the airframe rather
 * than against the world: a takeoff with no altitude climbs to the default,
 * and a landing targets the height *this* drone's landing gear parks it at.
 * A Freighter told to land at the Surveyor's rest height would stop with its
 * feet still in the air.
 */
export function beginCommand(
    command: DroneCommand,
    position: Vector3,
    yaw: number,
    spec: DroneFlightSpec,
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
            return {
                ...base,
                targetPosition: { ...position, y: spec.restHeight },
            };
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
        case 'takePhoto':
            // The airframe holds still for a beat so the shot isn't blurred;
            // the capture itself happens the frame this command completes.
            return {
                ...base,
                targetPosition: position,
                hoverSeconds: PHOTO_STABILIZE_SECONDS,
            };
        default:
            // Instant commands (sensor queries, setSpeed) resolve on the same
            // frame they're issued, so they have no target.
            return base;
    }
}

export type ControlStep = {
    linvel: Vector3;
    angvel: number;
    done: boolean;
};

function horizontalSpeed(velocity: Vector3): number {
    return Math.hypot(velocity.x, velocity.z);
}

function speedOf(velocity: Vector3): number {
    return Math.hypot(velocity.x, velocity.y, velocity.z);
}

/**
 * Largest speed along `direction` that keeps the horizontal component under
 * the cruise cap and the vertical component under the climb/descent cap.
 */
function directionSpeedCap(
    direction: Vector3,
    cruiseSpeed: number,
    climbCap: number,
    descentCap: number,
): number {
    const horizontal = Math.hypot(direction.x, direction.z);
    let cap = Number.POSITIVE_INFINITY;

    if (horizontal > 1e-6) {
        cap = Math.min(cap, cruiseSpeed / horizontal);
    }

    if (direction.y > 1e-6) {
        cap = Math.min(cap, climbCap / direction.y);
    }

    if (direction.y < -1e-6) {
        cap = Math.min(cap, descentCap / -direction.y);
    }

    return Number.isFinite(cap) ? cap : cruiseSpeed;
}

/**
 * Velocity setpoint toward a target: proportional close in, braking-curve
 * limited on approach, capped by the performance envelope at range.
 */
function desiredVelocityToward(
    position: Vector3,
    target: Vector3,
    control: ControlState,
    descentCap: number = control.spec.maxDescentRate,
    speedCap: number = Number.POSITIVE_INFINITY,
): Vector3 {
    const dx = target.x - position.x;
    const dy = target.y - position.y;
    const dz = target.z - position.z;
    const distance = Math.hypot(dx, dy, dz);

    if (distance < 1e-6) {
        return { x: 0, y: 0, z: 0 };
    }

    const direction = { x: dx / distance, y: dy / distance, z: dz / distance };
    const envelopeCap = directionSpeedCap(
        direction,
        Math.min(control.cruiseSpeed, speedCap),
        control.spec.maxClimbRate,
        descentCap,
    );
    const speed = Math.min(
        envelopeCap,
        SETTLE_GAIN * distance,
        Math.sqrt(2 * control.spec.brakingAcceleration * distance) *
            BRAKING_MARGIN,
    );

    return {
        x: direction.x * speed,
        y: direction.y * speed,
        z: direction.z * speed,
    };
}

/** Slew the commanded velocity toward the setpoint within acceleration limits. */
function slewVelocity(
    current: Vector3,
    desired: Vector3,
    dt: number,
    spec: DroneFlightSpec,
): Vector3 {
    const dx = desired.x - current.x;
    const dz = desired.z - current.z;
    const horizontalDelta = Math.hypot(dx, dz);
    const maxHorizontalDelta = spec.horizontalAcceleration * dt;
    const horizontalScale =
        horizontalDelta > maxHorizontalDelta
            ? maxHorizontalDelta / horizontalDelta
            : 1;

    const dy = desired.y - current.y;
    const maxVerticalDelta = spec.verticalAcceleration * dt;
    const verticalStep =
        Math.abs(dy) > maxVerticalDelta ? Math.sign(dy) * maxVerticalDelta : dy;

    return {
        x: current.x + dx * horizontalScale,
        y: current.y + verticalStep,
        z: current.z + dz * horizontalScale,
    };
}

function slewScalar(
    current: number,
    desired: number,
    maxDelta: number,
): number {
    const delta = desired - current;

    return current + Math.sign(delta) * Math.min(Math.abs(delta), maxDelta);
}

/** Bleed any residual yaw rate while no turn is commanded. */
function settleYawRate(control: ControlState, dt: number): number {
    control.yawRate = slewScalar(
        control.yawRate,
        0,
        control.spec.yawAcceleration * dt,
    );

    return control.yawRate;
}

/** Station-keep on a point: what GPS position-hold does between commands. */
function holdVelocity(
    control: ControlState,
    point: Vector3,
    position: Vector3,
    dt: number,
): Vector3 {
    const desired = desiredVelocityToward(
        position,
        point,
        control,
        control.spec.maxDescentRate,
        HOLD_SPEED_CAP,
    );
    control.velocity = slewVelocity(
        control.velocity,
        desired,
        dt,
        control.spec,
    );

    return control.velocity;
}

/**
 * Per-frame control while no command is active (user code is thinking):
 * airborne drones hold position; a landed drone just sits.
 */
export function computeHoldStep(
    control: ControlState,
    position: Vector3,
    dt: number,
): ControlStep {
    if (!control.airborne) {
        control.velocity = { x: 0, y: 0, z: 0 };

        return {
            linvel: control.velocity,
            angvel: settleYawRate(control, dt),
            done: false,
        };
    }

    if (!control.holdPoint) {
        control.holdPoint = { x: position.x, y: position.y, z: position.z };
    }

    return {
        linvel: holdVelocity(control, control.holdPoint, position, dt),
        angvel: settleYawRate(control, dt),
        done: false,
    };
}

/** Computes the velocity to command this frame, and whether the active command has completed. */
export function computeControlStep(
    active: ActiveCommand,
    position: Vector3,
    yaw: number,
    dt: number,
    control: ControlState,
): ControlStep {
    const command = active.command;

    if (command.type === 'setSpeed') {
        control.cruiseSpeed = clamp(
            command.speed,
            control.spec.minCruiseSpeed,
            control.spec.maxCruiseSpeed,
        );
    }

    // Motors spool up on the pad before the takeoff climb begins.
    if (
        command.type === 'takeoff' &&
        !control.airborne &&
        active.elapsed < control.spec.spoolSeconds
    ) {
        control.velocity = { x: 0, y: 0, z: 0 };

        return {
            linvel: control.velocity,
            angvel: settleYawRate(control, dt),
            done: false,
        };
    }

    if (command.type === 'hover' || command.type === 'takePhoto') {
        const done = active.elapsed + dt >= (active.hoverSeconds ?? 0);
        const linvel = holdVelocity(
            control,
            active.startPosition,
            position,
            dt,
        );

        if (done) {
            control.holdPoint = { ...active.startPosition };
        }

        return { linvel, angvel: settleYawRate(control, dt), done };
    }

    if (command.type === 'turn' && active.targetYaw !== undefined) {
        const error = normalizeAngle(active.targetYaw - yaw);
        const linvel = holdVelocity(
            control,
            active.startPosition,
            position,
            dt,
        );

        if (
            Math.abs(error) < YAW_EPSILON &&
            Math.abs(control.yawRate) < YAW_SETTLE_RATE
        ) {
            control.yawRate = 0;
            control.holdPoint = { ...active.startPosition };

            return { linvel, angvel: 0, done: true };
        }

        const desiredRate =
            Math.sign(error) *
            Math.min(
                control.spec.maxYawRate,
                Math.sqrt(2 * control.spec.yawAcceleration * Math.abs(error)) *
                    BRAKING_MARGIN,
                5 * Math.abs(error),
            );
        control.yawRate = slewScalar(
            control.yawRate,
            desiredRate,
            control.spec.yawAcceleration * dt,
        );

        return { linvel, angvel: control.yawRate, done: false };
    }

    if (active.targetPosition) {
        const target = active.targetPosition;

        if (command.type === 'takeoff') {
            control.airborne = true;
        }

        const landing = command.type === 'land';
        const arrived = landing
            ? Math.abs(position.y - target.y) < LANDING_EPSILON &&
              Math.abs(control.velocity.y) < SETTLE_SPEED &&
              horizontalSpeed(control.velocity) < SETTLE_SPEED
            : Math.hypot(
                  target.x - position.x,
                  target.y - position.y,
                  target.z - position.z,
              ) < POSITION_EPSILON && speedOf(control.velocity) < SETTLE_SPEED;

        if (arrived) {
            // Bleed the residual arrival speed within acceleration limits
            // instead of snapping to zero; the shared control state carries
            // it into the next command or hold.
            control.velocity = slewVelocity(
                control.velocity,
                { x: 0, y: 0, z: 0 },
                dt,
                control.spec,
            );

            if (landing) {
                control.velocity = { x: 0, y: 0, z: 0 };
                control.airborne = false;
                control.holdPoint = null;
            } else {
                control.holdPoint = { ...target };
            }

            return {
                linvel: control.velocity,
                angvel: settleYawRate(control, dt),
                done: true,
            };
        }

        // Landings plan the descent so the drone reaches the flare gate
        // already at flare speed, then creeps down to touchdown.
        const descentCap = landing
            ? Math.min(
                  control.spec.maxDescentRate,
                  Math.sqrt(
                      FLARE_DESCENT_RATE ** 2 +
                          2 *
                              control.spec.verticalAcceleration *
                              0.8 *
                              Math.max(0, position.y - FLARE_ALTITUDE),
                  ),
              )
            : control.spec.maxDescentRate;
        const desired = desiredVelocityToward(
            position,
            target,
            control,
            descentCap,
        );
        control.velocity = slewVelocity(
            control.velocity,
            desired,
            dt,
            control.spec,
        );

        return {
            linvel: control.velocity,
            angvel: settleYawRate(control, dt),
            done: false,
        };
    }

    // Instant commands (sensor queries, setSpeed): hold station this frame
    // and resolve immediately.
    const holdPoint = control.holdPoint ?? position;

    return {
        linvel: control.airborne
            ? holdVelocity(control, holdPoint, position, dt)
            : { x: 0, y: 0, z: 0 },
        angvel: settleYawRate(control, dt),
        done: true,
    };
}
