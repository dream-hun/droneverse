import { useFrame } from '@react-three/fiber';
import { useRapier } from '@react-three/rapier';
import type { RapierRigidBody } from '@react-three/rapier';
import { useCallback, useEffect, useRef } from 'react';
import type { RefObject } from 'react';
import { postJson } from '@/lib/http';
import { createBridge } from '@/lib/simulator/commands';
import type { ActiveCommand, Vector3 } from '@/lib/simulator/commands';
import { droneEngine } from '@/lib/simulator/engine-audio';
import type { FlightVisualState } from '@/lib/simulator/flight-state';
import { resetFlightVisualState } from '@/lib/simulator/flight-state';
import { gradeRun } from '@/lib/simulator/grader';
import {
    beginCommand,
    computeControlStep,
    computeHoldStep,
    createControlState,
    createWindField,
    DRAG_TILT_COEFFICIENT,
    forwardVector,
    GRAVITY,
    MAX_CLIMB_RATE,
    MAX_TILT,
    quaternionYaw,
    REST_HEIGHT,
    SPOOL_SECONDS,
} from '@/lib/simulator/physics';
import type { ControlState, WindField } from '@/lib/simulator/physics';
import type { SimulatorSession } from '@/lib/simulator/session';
import {
    advanceTelemetry,
    hasExceededTimeLimit,
} from '@/lib/simulator/telemetry';
import { droneVoice } from '@/lib/simulator/voice';
import { SimulationWorkerClient } from '@/lib/simulator/worker-client';
import type { EnvironmentConfig, SuccessCriteria } from '@/types/simulator';

type UseDroneSimulationArgs = {
    rigidBodyRef: RefObject<RapierRigidBody | null>;
    session: SimulatorSession;
    environment: EnvironmentConfig;
    successCriteria: SuccessCriteria;
    maxScore: number;
    attemptUrl: string;
    flightState: FlightVisualState;
};

const ROTOR_VISUAL_MAX_SPEED = 82; // rad/s at full throttle
const ROTOR_SLEW_RATE = 110; // rad/s^2 spool feel
const TILT_SMOOTHING_TAU = 0.22; // seconds
const BATTERY_IDLE_DRAIN = 0.04; // %/s with motors idle
const BATTERY_THROTTLE_DRAIN = 0.22; // additional %/s at full throttle

function clamp(value: number, min: number, max: number): number {
    return Math.min(max, Math.max(min, value));
}

function compassDegrees(radians: number): number {
    return ((((-radians * 180) / Math.PI) % 360) + 360) % 360;
}

function modeLabel(
    active: ActiveCommand | null,
    control: ControlState,
    previous: string,
): string {
    if (!active) {
        return control.airborne ? 'HOLD' : previous;
    }

    switch (active.command.type) {
        case 'takeoff':
            return !control.airborne && active.elapsed < SPOOL_SECONDS
                ? 'SPOOL UP'
                : 'TAKEOFF';
        case 'land':
            return 'LANDING';
        case 'hover':
            return 'HOVER';
        case 'turn':
            return 'TURN';
        case 'moveForward':
        case 'moveTo':
        case 'setAltitude':
            return 'ENROUTE';
        default:
            return previous;
    }
}

/**
 * Orchestrates one simulator run: the sandbox worker (via
 * SimulationWorkerClient), the per-frame physics control loop, telemetry,
 * grading, and result submission. Command execution flows worker → bridge →
 * physics step → resolve, so user code only advances when the drone has
 * physically finished each command. All UI-visible state is written to the
 * SimulatorSession store, which the page subscribes to from outside the
 * Canvas; per-frame flight data goes to the mutable FlightVisualState read
 * by the drone model, camera rig, and HUD (accessed through a ref because
 * it is written outside React's render cycle).
 */
export function useDroneSimulation({
    rigidBodyRef,
    session,
    environment,
    successCriteria,
    maxScore,
    attemptUrl,
    flightState,
}: UseDroneSimulationArgs) {
    const { world, rapier } = useRapier();
    const clientRef = useRef<SimulationWorkerClient | null>(null);
    const bridgeRef = useRef(createBridge(successCriteria.waypoints.length));
    const controlRef = useRef(createControlState());
    const windRef = useRef<WindField | null>(null);
    const previousVelocityRef = useRef<Vector3>({ x: 0, y: 0, z: 0 });
    const flightStateRef = useRef(flightState);
    const codeRef = useRef('');

    const appendLog = useCallback(
        (level: 'log' | 'warn' | 'error', args: unknown[]) => {
            const text = args
                .map((arg) =>
                    typeof arg === 'string' ? arg : JSON.stringify(arg),
                )
                .join(' ');
            session.appendLog({ level, text });
        },
        [session],
    );

    const stop = useCallback(() => {
        clientRef.current?.terminate();
        clientRef.current = null;
        bridgeRef.current.active = null;
        flightStateRef.current.armed = false;
        flightStateRef.current.mode = 'STANDBY';
        droneEngine.stop();
        droneVoice.cancel();
        droneVoice.announceEvent('aborted');
        session.markStopped();
    }, [session]);

    const finishRun = useCallback(
        (timedOut: boolean, faulted = false) => {
            // The time limit and the worker's "finished" message can land in
            // the same frame; only the first one may grade, submit, and speak.
            if (!session.isRunning()) {
                return;
            }

            droneEngine.stop();
            droneVoice.announceEvent(faulted ? 'fault' : 'complete');

            clientRef.current?.terminate();
            clientRef.current = null;
            bridgeRef.current.active = null;
            bridgeRef.current.telemetry.timedOut = timedOut;
            flightStateRef.current.armed = false;

            const grade = gradeRun(
                bridgeRef.current.telemetry,
                successCriteria,
                maxScore,
            );

            session.finish(grade);

            postJson(attemptUrl, {
                score: grade.score,
                stars: grade.stars,
                completed: grade.completed,
                code: codeRef.current,
            }).catch(() => {
                appendLog('warn', [
                    'Could not save your progress for this run.',
                ]);
            });
        },
        [appendLog, attemptUrl, maxScore, session, successCriteria],
    );

    const resetDrone = useCallback(
        (body: RapierRigidBody) => {
            body.setTranslation(
                {
                    x: environment.start.x,
                    y: REST_HEIGHT,
                    z: environment.start.z,
                },
                true,
            );
            body.setLinvel({ x: 0, y: 0, z: 0 }, true);
            body.setAngvel({ x: 0, y: 0, z: 0 }, true);

            const half = environment.start.yaw / 2;
            body.setRotation(
                { x: 0, y: Math.sin(half), z: 0, w: Math.cos(half) },
                true,
            );
        },
        [environment.start],
    );

    const run = useCallback(
        (code: string) => {
            clientRef.current?.terminate();

            const body = rigidBodyRef.current;

            if (!body) {
                return;
            }

            codeRef.current = code;
            bridgeRef.current = createBridge(successCriteria.waypoints.length);
            controlRef.current = createControlState();
            windRef.current = createWindField(
                environment.wind?.speed ?? 1.8,
                environment.wind?.directionDeg !== undefined
                    ? (environment.wind.directionDeg * Math.PI) / 180
                    : undefined,
            );
            previousVelocityRef.current = { x: 0, y: 0, z: 0 };
            resetFlightVisualState(flightStateRef.current);
            resetDrone(body);

            const client = new SimulationWorkerClient({
                onCommand: (id, command) => {
                    const currentBody = rigidBodyRef.current;

                    if (!currentBody) {
                        return;
                    }

                    droneVoice.announceCommand(command);

                    bridgeRef.current.active = {
                        ...beginCommand(
                            command,
                            currentBody.translation(),
                            quaternionYaw(currentBody.rotation()),
                        ),
                        resolve: (result: unknown) =>
                            client.resolveCommand(id, result),
                    };
                },
                onLog: appendLog,
                onFinished: () => finishRun(false),
                onError: (message) => {
                    appendLog('error', [message]);
                    finishRun(false, true);
                },
            });

            clientRef.current = client;
            droneEngine.start();
            droneVoice.cancel();
            droneVoice.announceEvent('armed');
            session.begin();
            client.start(code);
        },
        [
            appendLog,
            environment.wind,
            finishRun,
            resetDrone,
            rigidBodyRef,
            session,
            successCriteria.waypoints.length,
        ],
    );

    useEffect(
        () => session.registerControls({ run, stop }),
        [run, session, stop],
    );

    // Navigating away mid-run must not leave the sandbox worker (or the audio
    // graph) alive.
    useEffect(
        () => () => {
            clientRef.current?.terminate();
            clientRef.current = null;
            droneEngine.release();
        },
        [],
    );

    const handleCollision = useCallback((kind: string | undefined) => {
        if (kind === 'ground') {
            return;
        }

        bridgeRef.current.telemetry.collisions += 1;
    }, []);

    useFrame((_, dt) => {
        const body = rigidBodyRef.current;
        const fs = flightStateRef.current;

        if (!body || dt <= 0) {
            return;
        }

        if (!session.isRunning()) {
            // Idle: spool the rotors down (or keep them turning if the run
            // was aborted mid-air) and relax the visual tilt.
            const idleTarget = fs.airborne ? 38 : 0;
            const rotorDelta = idleTarget - fs.rotorSpeed;
            fs.rotorSpeed +=
                Math.sign(rotorDelta) *
                Math.min(Math.abs(rotorDelta), ROTOR_SLEW_RATE * dt);
            const decay = 1 - Math.exp(-dt / TILT_SMOOTHING_TAU);
            fs.pitch -= fs.pitch * decay;
            fs.roll -= fs.roll * decay;
            fs.groundSpeed = 0;
            fs.verticalSpeed = 0;
            droneEngine.setState(
                fs.rotorSpeed / ROTOR_VISUAL_MAX_SPEED,
                fs.rotorSpeed,
                0,
            );

            return;
        }

        const bridge = bridgeRef.current;
        const control = controlRef.current;
        const wind = windRef.current;
        const position = body.translation();

        advanceTelemetry(bridge, position, dt, successCriteria.waypoints);

        if (hasExceededTimeLimit(bridge, successCriteria.max_time_seconds)) {
            finishRun(true);

            return;
        }

        const yaw = quaternionYaw(body.rotation());
        const step = bridge.active
            ? computeControlStep(bridge.active, position, yaw, dt, control)
            : computeHoldStep(control, position, dt);

        // Gusts push the airborne drone around; the position loop above
        // constantly corrects, producing realistic station-keeping wander.
        const elapsed = bridge.telemetry.elapsedSeconds;
        const gust =
            control.airborne && wind
                ? wind.gust(elapsed)
                : { x: 0, y: 0, z: 0 };
        const applied = {
            x: step.linvel.x + gust.x,
            y: step.linvel.y + gust.y,
            z: step.linvel.z + gust.z,
        };

        body.setLinvel(applied, true);
        body.setAngvel({ x: 0, y: step.angvel, z: 0 }, true);

        // --- Visual flight state: tilt, throttle, rotors, battery, HUD ---
        const previous = previousVelocityRef.current;
        const accelX = (control.velocity.x - previous.x) / dt;
        const accelZ = (control.velocity.z - previous.z) / dt;
        previousVelocityRef.current = { ...control.velocity };

        const forward = forwardVector(yaw);
        const right = { x: -forward.z, z: forward.x };
        const forwardAccel =
            accelX * forward.x +
            accelZ * forward.z +
            DRAG_TILT_COEFFICIENT *
                (control.velocity.x * forward.x +
                    control.velocity.z * forward.z);
        const rightAccel =
            accelX * right.x +
            accelZ * right.z +
            DRAG_TILT_COEFFICIENT *
                (control.velocity.x * right.x + control.velocity.z * right.z);

        const targetPitch = clamp(
            -Math.atan2(forwardAccel, GRAVITY),
            -MAX_TILT,
            MAX_TILT,
        );
        const targetRoll = clamp(
            -Math.atan2(rightAccel, GRAVITY),
            -MAX_TILT,
            MAX_TILT,
        );
        const smoothing = 1 - Math.exp(-dt / TILT_SMOOTHING_TAU);
        fs.pitch += (targetPitch - fs.pitch) * smoothing;
        fs.roll += (targetRoll - fs.roll) * smoothing;

        const tiltMagnitude = Math.hypot(fs.pitch, fs.roll);
        let throttle: number;

        if (bridge.active?.command.type === 'takeoff' && !control.airborne) {
            throttle =
                0.15 +
                0.45 * Math.min(1, bridge.active.elapsed / SPOOL_SECONDS);
        } else if (control.airborne) {
            throttle = clamp(
                0.55 +
                    0.28 * clamp(control.velocity.y / MAX_CLIMB_RATE, -1, 1) +
                    0.18 * (tiltMagnitude / MAX_TILT),
                0.15,
                1,
            );
        } else {
            throttle = 0.16;
        }

        fs.throttle = throttle;
        const rotorTarget = throttle * ROTOR_VISUAL_MAX_SPEED;
        const rotorDelta = rotorTarget - fs.rotorSpeed;
        fs.rotorSpeed +=
            Math.sign(rotorDelta) *
            Math.min(Math.abs(rotorDelta), ROTOR_SLEW_RATE * dt);

        fs.batteryPct = Math.max(
            0,
            fs.batteryPct -
                (BATTERY_IDLE_DRAIN + BATTERY_THROTTLE_DRAIN * throttle) * dt,
        );

        fs.armed = true;
        fs.airborne = control.airborne;
        fs.altitude = Math.max(0, position.y - REST_HEIGHT);
        fs.groundSpeed = Math.hypot(applied.x, applied.z);
        fs.verticalSpeed = applied.y;
        fs.headingDeg = compassDegrees(yaw);
        fs.mode = modeLabel(bridge.active, control, fs.mode);

        droneEngine.setState(fs.throttle, fs.rotorSpeed, fs.groundSpeed);

        if (wind) {
            const along = {
                x: Math.sin(wind.directionRad),
                z: -Math.cos(wind.directionRad),
            };
            fs.windSpeed = Math.hypot(
                along.x * wind.meanSpeed + gust.x,
                along.z * wind.meanSpeed + gust.z,
            );
            fs.windHeadingDeg =
                ((((wind.directionRad * 180) / Math.PI) % 360) + 360) % 360;
        }

        if (!bridge.active) {
            return;
        }

        bridge.active.elapsed += dt;

        if (!step.done) {
            return;
        }

        const finishedCommand = bridge.active.command;
        let queryResult: unknown = null;

        if (finishedCommand.type === 'land') {
            bridge.telemetry.landed = true;
            fs.mode = 'LANDED';
        } else if (finishedCommand.type === 'getPosition') {
            queryResult = { x: position.x, y: position.y, z: position.z };
        } else if (finishedCommand.type === 'getHeading') {
            queryResult = (yaw * 180) / Math.PI;
        } else if (finishedCommand.type === 'getAltitude') {
            queryResult = position.y;
        } else if (finishedCommand.type === 'getBattery') {
            queryResult = Math.round(fs.batteryPct);
        } else if (finishedCommand.type === 'getDistanceAhead') {
            const ray = new rapier.Ray(
                { x: position.x, y: position.y, z: position.z },
                { x: forward.x, y: 0, z: forward.z },
            );
            const hit = world.castRay(
                ray,
                20,
                true,
                undefined,
                undefined,
                undefined,
                body,
            );
            queryResult = hit ? hit.timeOfImpact : 20;
        }

        bridge.active.resolve(queryResult);
        bridge.active = null;
    });

    return { handleCollision };
}
