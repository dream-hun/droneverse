import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { describe, expect, it } from 'vitest';
import type { ActiveCommand, Vector3 } from './commands';
import {
    beginCommand,
    computeControlStep,
    computeHoldStep,
    createControlState,
} from './physics';
import type { DroneFlightSpec } from '@/types/drone';

/**
 * What the choice of airframe actually buys.
 *
 * The picker is only a configuration if the configuration reaches the flight
 * model, and "reaches the flight model" is not something a type checker can
 * assert: every field of the spec threads through functions that used to read
 * a module constant, and one left behind would silently fly every drone on
 * the Surveyor's number for that one quantity. So each of these flies a drone
 * and measures the thing the spec claims to control.
 *
 * Flown against the seeded fleet rather than against invented specs, for the
 * same reason airframe.test.ts is: these are claims about drones pilots
 * actually get.
 */

type FleetEntry = {
    slug: string;
    name: string;
    flight_spec: DroneFlightSpec;
};

const fleet: FleetEntry[] = JSON.parse(
    readFileSync(
        fileURLToPath(
            new URL(
                '../../../../database/seeders/drone-fleet.json',
                import.meta.url,
            ),
        ),
        'utf8',
    ),
);

function droneNamed(slug: string): DroneFlightSpec {
    const drone = fleet.find((entry) => entry.slug === slug);

    if (!drone) {
        throw new Error(`No drone seeded with slug [${slug}].`);
    }

    return drone.flight_spec;
}

const CADET = droneNamed('tr-4-cadet');
const VECTOR = droneNamed('vx-4-vector');
const SURVEYOR = droneNamed('hx-6-surveyor');

const FRAME = 1 / 60;

/** Resolve a command into an active one, with a resolve that records nothing. */
function activate(
    command: Parameters<typeof beginCommand>[0],
    position: Vector3,
    yaw: number,
    spec: DroneFlightSpec,
): ActiveCommand {
    return {
        ...beginCommand(command, position, yaw, spec),
        resolve: () => {},
    };
}

/**
 * Fly one command to completion (or to the frame cap) and report what happened.
 *
 * Integrates the commanded velocity rather than running a physics engine: the
 * control loop's output *is* the velocity setpoint, and the rigid body tracks
 * it, so integrating it is the same flight without standing up Rapier.
 */
function fly(
    spec: DroneFlightSpec,
    command: Parameters<typeof beginCommand>[0],
    options: { airborne?: boolean; from?: Vector3; maxFrames?: number } = {},
) {
    const position: Vector3 = options.from
        ? { ...options.from }
        : { x: 0, y: options.airborne ? 2 : spec.restHeight, z: 0 };
    const control = createControlState(spec);
    control.airborne = options.airborne ?? false;

    const active = activate(command, { ...position }, 0, spec);
    const maxFrames = options.maxFrames ?? 60 * 60;

    let frames = 0;
    let peakSpeed = 0;
    let peakClimb = 0;
    let peakYawRate = 0;
    let yaw = 0;
    let done = false;

    while (frames < maxFrames && !done) {
        const step = computeControlStep(active, position, yaw, FRAME, control);

        position.x += step.linvel.x * FRAME;
        position.y += step.linvel.y * FRAME;
        position.z += step.linvel.z * FRAME;
        yaw += step.angvel * FRAME;

        peakSpeed = Math.max(
            peakSpeed,
            Math.hypot(step.linvel.x, step.linvel.z),
        );
        peakClimb = Math.max(peakClimb, step.linvel.y);
        peakYawRate = Math.max(peakYawRate, Math.abs(step.angvel));

        active.elapsed += FRAME;
        frames += 1;
        done = step.done;
    }

    return {
        position,
        yaw,
        seconds: frames * FRAME,
        peakSpeed,
        peakClimb,
        peakYawRate,
        done,
        control,
    };
}

describe('the flight envelope reaches the control loop', () => {
    it('starts a run at the airframe’s own cruise speed', () => {
        expect(createControlState(CADET).cruiseSpeed).toBe(CADET.cruiseSpeed);
        expect(createControlState(VECTOR).cruiseSpeed).toBe(VECTOR.cruiseSpeed);
        expect(CADET.cruiseSpeed).not.toBe(VECTOR.cruiseSpeed);
    });

    it('caps cruise speed at the airframe’s own ceiling', () => {
        // Both drones are asked for a speed neither may fly, so what each
        // settles on is its own ceiling and nothing else.
        const beyondBoth =
            Math.max(CADET.maxCruiseSpeed, VECTOR.maxCruiseSpeed) + 50;

        [CADET, VECTOR].forEach((spec) => {
            const control = createControlState(spec);
            const command = { type: 'setSpeed', speed: beyondBoth } as const;

            computeControlStep(
                activate(command, { x: 0, y: 2, z: 0 }, 0, spec),
                { x: 0, y: 2, z: 0 },
                0,
                FRAME,
                control,
            );

            expect(control.cruiseSpeed).toBe(spec.maxCruiseSpeed);
        });
    });

    it('holds each airframe to its own floor on a slow request', () => {
        [CADET, VECTOR].forEach((spec) => {
            const control = createControlState(spec);
            const command = { type: 'setSpeed', speed: 0.01 } as const;

            computeControlStep(
                activate(command, { x: 0, y: 2, z: 0 }, 0, spec),
                { x: 0, y: 2, z: 0 },
                0,
                FRAME,
                control,
            );

            expect(control.cruiseSpeed).toBe(spec.minCruiseSpeed);
        });
    });

    it('flies the racer to a distant waypoint faster than the trainer', () => {
        const command = { type: 'moveTo', x: 0, y: 2, z: -60 } as const;
        const cadet = fly(CADET, command, { airborne: true });
        const vector = fly(VECTOR, command, { airborne: true });

        expect(cadet.done).toBe(true);
        expect(vector.done).toBe(true);
        expect(vector.seconds).toBeLessThan(cadet.seconds);

        // And each stayed inside its own cruise cap getting there.
        expect(cadet.peakSpeed).toBeLessThanOrEqual(CADET.cruiseSpeed + 1e-6);
        expect(vector.peakSpeed).toBeLessThanOrEqual(VECTOR.cruiseSpeed + 1e-6);
        expect(vector.peakSpeed).toBeGreaterThan(cadet.peakSpeed);
    });

    it('climbs no faster than the airframe’s climb rate', () => {
        const command = { type: 'setAltitude', altitude: 40 } as const;

        [CADET, VECTOR, SURVEYOR].forEach((spec) => {
            const flight = fly(spec, command, { airborne: true });

            expect(flight.peakClimb).toBeLessThanOrEqual(
                spec.maxClimbRate + 1e-6,
            );
            // Long enough a climb that the cap is actually reached, so this
            // is a measurement of the cap rather than of the acceleration.
            expect(flight.peakClimb).toBeGreaterThan(spec.maxClimbRate * 0.95);
        });
    });

    it('turns no faster than the airframe’s yaw rate', () => {
        const command = { type: 'turn', degrees: 180 } as const;

        [CADET, VECTOR].forEach((spec) => {
            const flight = fly(spec, command, { airborne: true });

            expect(flight.done).toBe(true);
            expect(flight.peakYawRate).toBeLessThanOrEqual(
                spec.maxYawRate + 1e-6,
            );
        });

        expect(fly(VECTOR, command, { airborne: true }).seconds).toBeLessThan(
            fly(CADET, command, { airborne: true }).seconds,
        );
    });

    it('spools each airframe for its own count before lifting off', () => {
        const command = { type: 'takeoff' } as const;

        [CADET, VECTOR].forEach((spec) => {
            const control = createControlState(spec);
            const position = { x: 0, y: spec.restHeight, z: 0 };
            const active = activate(command, { ...position }, 0, spec);

            // One frame short of the spool: still on the pad, not climbing.
            active.elapsed = spec.spoolSeconds - 2 * FRAME;
            const spooling = computeControlStep(
                active,
                position,
                0,
                FRAME,
                control,
            );

            expect(spooling.linvel.y).toBe(0);
            expect(control.airborne).toBe(false);

            // One frame past it: climbing.
            active.elapsed = spec.spoolSeconds + FRAME;
            const lifting = computeControlStep(
                active,
                position,
                0,
                FRAME,
                control,
            );

            expect(lifting.linvel.y).toBeGreaterThan(0);
            expect(control.airborne).toBe(true);
        });
    });

    it('lands each airframe on its own landing gear', () => {
        // A drone told to land targets the height its own legs park it at.
        // Landing a Freighter at the Surveyor's rest height would leave it
        // hanging, and landing the Vector there would bury it.
        fleet.forEach((drone) => {
            const spec = drone.flight_spec;
            const resolved = beginCommand(
                { type: 'land' },
                { x: 0, y: 3, z: 0 },
                0,
                spec,
            );

            expect(resolved.targetPosition?.y).toBe(spec.restHeight);
        });
    });

    it('station-keeps inside the hold cap while user code is thinking', () => {
        // No command is active and the drone is pushed off its hold point;
        // it corrects, and does so gently rather than at cruise speed.
        const control = createControlState(VECTOR);
        control.airborne = true;
        control.holdPoint = { x: 0, y: 2, z: 0 };

        const step = computeHoldStep(control, { x: 6, y: 2, z: 0 }, FRAME);

        expect(step.linvel.x).toBeLessThan(0);
        expect(Math.hypot(step.linvel.x, step.linvel.z)).toBeLessThan(
            VECTOR.cruiseSpeed,
        );
    });

    it('accelerates within the airframe’s own limit', () => {
        // One frame of a standing start: the commanded velocity cannot have
        // moved further than the acceleration cap allows in that frame.
        [CADET, VECTOR].forEach((spec) => {
            const control = createControlState(spec);
            control.airborne = true;
            const position = { x: 0, y: 2, z: 0 };
            const active = activate(
                { type: 'moveTo', x: 0, y: 2, z: -60 },
                { ...position },
                0,
                spec,
            );

            const step = computeControlStep(
                active,
                position,
                0,
                FRAME,
                control,
            );

            expect(
                Math.hypot(step.linvel.x, step.linvel.z),
            ).toBeLessThanOrEqual(spec.horizontalAcceleration * FRAME + 1e-9);
        });
    });
});
