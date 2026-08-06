import { router } from '@inertiajs/react';
import { useFrame, useThree } from '@react-three/fiber';
import { useRapier } from '@react-three/rapier';
import type { RapierRigidBody } from '@react-three/rapier';
import { useCallback, useEffect, useRef } from 'react';
import type { RefObject } from 'react';
import { postJson } from '@/lib/http';
import { createBridge } from '@/lib/simulator/commands';
import type { ActiveCommand, Vector3 } from '@/lib/simulator/commands';
import { scanEnvironment } from '@/lib/simulator/detection';
import { droneEngine } from '@/lib/simulator/engine-audio';
import type { FlightVisualState } from '@/lib/simulator/flight-state';
import { resetFlightVisualState } from '@/lib/simulator/flight-state';
import { gradeRun } from '@/lib/simulator/grader';
import { clamp, compassDegrees, normalizeDegrees } from '@/lib/simulator/math';
import { DronePhotoCamera } from '@/lib/simulator/photo';
import {
    beginCommand,
    computeControlStep,
    computeHoldStep,
    createControlState,
    createWindField,
    DRAG_TILT_COEFFICIENT,
    forwardVector,
    GRAVITY,
    quaternionYaw,
} from '@/lib/simulator/physics';
import type { ControlState, WindField } from '@/lib/simulator/physics';
import type { SimulatorSession } from '@/lib/simulator/session';
import {
    advanceTelemetry,
    hasExceededTimeLimit,
} from '@/lib/simulator/telemetry';
import { createUploadQueue } from '@/lib/simulator/upload-queue';
import { droneVoice } from '@/lib/simulator/voice';
import { SimulationWorkerClient } from '@/lib/simulator/worker-client';
import type { DroneModelSummary } from '@/types/drone';
import type {
    EnvironmentConfig,
    RunResult,
    SuccessCriteria,
} from '@/types/simulator';

type UseDroneSimulationArgs = {
    rigidBodyRef: RefObject<RapierRigidBody | null>;
    session: SimulatorSession;
    environment: EnvironmentConfig;
    successCriteria: SuccessCriteria;
    maxScore: number;
    attemptUrl: string;
    photoUrl: string;
    flightState: FlightVisualState;
    /** The airframe the pilot is flying, as resolved server-side. */
    drone: DroneModelSummary;
};

const ROTOR_SLEW_RATE = 110; // rad/s^2 spool feel
const TILT_SMOOTHING_TAU = 0.22; // seconds

/**
 * Scratch vectors for the frame loop.
 *
 * The loop below runs sixty times a second for the length of a flight and
 * every vector in it was a fresh object literal — the commanded velocity,
 * the angular velocity, the still-air gust, the copy of last frame's
 * velocity. None of them outlives the frame that makes them: Rapier reads
 * the velocities synchronously inside `setLinvel`/`setAngvel`, and the
 * previous-velocity copy is read once, next frame, and overwritten. So they
 * are written into these instead, and the loop allocates nothing at all.
 *
 * Module scope rather than refs because there is one flight at a time, and
 * nothing here holds a value across a frame boundary except
 * `previousVelocity`, which is rewritten from scratch each frame anyway.
 */
const NO_GUST: Vector3 = { x: 0, y: 0, z: 0 };
const appliedVelocity: Vector3 = { x: 0, y: 0, z: 0 };
const appliedSpin: Vector3 = { x: 0, y: 0, z: 0 };

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
            return !control.airborne &&
                active.elapsed < control.spec.spoolSeconds
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
        case 'takePhoto':
            return 'PHOTO';
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
    photoUrl,
    flightState,
    drone,
}: UseDroneSimulationArgs) {
    const { world, rapier } = useRapier();
    const gl = useThree((state) => state.gl);
    const scene = useThree((state) => state.scene);
    const clientRef = useRef<SimulationWorkerClient | null>(null);
    const bridgeRef = useRef(createBridge(successCriteria.waypoints.length));
    const controlRef = useRef(createControlState(drone.flight));
    const windRef = useRef<WindField | null>(null);
    const previousVelocityRef = useRef<Vector3>({ x: 0, y: 0, z: 0 });
    const flightStateRef = useRef(flightState);
    const codeRef = useRef('');
    const photoCameraRef = useRef<DronePhotoCamera | null>(null);
    const washAnnouncedRef = useRef(false);
    const uploadsRef = useRef(createUploadQueue());

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

    /**
     * Fires the stills camera at the drone's current pose. The capture is
     * synchronous (it happens inside the frame the takePhoto command
     * completes); the upload to the pilot's photo log runs in the
     * background so the flight never stalls on the network.
     */
    const capturePhoto = useCallback(
        (label: string | undefined, position: Vector3, yaw: number) => {
            const span = Math.max(
                environment.bounds.width,
                environment.bounds.depth,
            );
            photoCameraRef.current ??= new DronePhotoCamera();

            const shot = photoCameraRef.current.capture(
                gl,
                scene,
                position,
                yaw,
                span * 12,
            );
            const capturedAt = {
                x: position.x,
                y: position.y,
                z: position.z,
            };

            if (!shot) {
                appendLog('warn', [
                    'Camera fault: the photo could not be captured.',
                ]);

                return { captured: false, label: label ?? null };
            }

            bridgeRef.current.telemetry.photoPositions.push(capturedAt);
            const index = bridgeRef.current.telemetry.photoPositions.length;
            flightStateRef.current.photosTaken = index;

            appendLog('log', [
                `Photo ${index} captured${label ? ` — "${label}"` : ''}.`,
            ]);

            uploadsRef.current
                .enqueue(() =>
                    postJson(photoUrl, {
                        image: shot.dataUrl,
                        label: label ?? null,
                        x: capturedAt.x,
                        y: capturedAt.y,
                        z: capturedAt.z,
                        heading: compassDegrees(yaw),
                    }),
                )
                .then(() => {
                    appendLog('log', [
                        `Photo ${index} saved to your photo log.`,
                    ]);
                })
                .catch(() => {
                    appendLog('warn', [
                        `Photo ${index} could not be saved to your photo log.`,
                    ]);
                });

            return {
                captured: true,
                index,
                label: label ?? null,
                position: capturedAt,
            };
        },
        [appendLog, environment.bounds, gl, photoUrl, scene],
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

            const telemetry = bridgeRef.current.telemetry;

            // Shown straight away so the pilot is not left waiting on the
            // network, but this is a preview: the server grades the flight
            // itself and its verdict replaces this one below.
            session.finish(gradeRun(telemetry, successCriteria, maxScore));

            postJson<{ result: RunResult }>(attemptUrl, {
                code: codeRef.current,
                collisions: telemetry.collisions,
                path: telemetry.path,
                photos: telemetry.photoPositions,
            })
                .then(({ result }) => {
                    session.finish(result);

                    // The progress badges and the reference-solution gate are
                    // rendered from server state, so pull them again now the
                    // attempt has been recorded. A partial reload keeps the
                    // editor contents and the live scene untouched.
                    router.reload({ only: ['progress', 'solution'] });
                })
                .catch(() => {
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
                    y: drone.flight.restHeight,
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
        [drone.flight.restHeight, environment.start],
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
            controlRef.current = createControlState(drone.flight);
            windRef.current = createWindField(
                environment.wind?.speed ?? 1.8,
                environment.wind?.directionDeg !== undefined
                    ? (environment.wind.directionDeg * Math.PI) / 180
                    : undefined,
            );
            previousVelocityRef.current = { x: 0, y: 0, z: 0 };
            washAnnouncedRef.current = false;
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
                            drone.flight,
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
            drone.flight,
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
            photoCameraRef.current?.release();
            photoCameraRef.current = null;
        },
        [],
    );

    const handleCollision = useCallback((kind: string | undefined) => {
        if (kind === 'ground') {
            return;
        }

        bridgeRef.current.telemetry.collisions += 1;
    }, []);

    /** Wash beams are sensors, so they report intersections, not collisions. */
    const handleSensorEnter = useCallback(
        (kind: string | undefined) => {
            const telemetry = bridgeRef.current.telemetry;

            if (kind === 'wash-entry') {
                telemetry.washEntryHit = true;
            } else if (kind === 'wash-exit') {
                telemetry.washExitHit = true;
            } else {
                return;
            }

            if (
                telemetry.washEntryHit &&
                telemetry.washExitHit &&
                !washAnnouncedRef.current
            ) {
                washAnnouncedRef.current = true;
                appendLog('log', ['Wash cycle complete — airframe clean.']);
                droneVoice.announceEvent('washed');
            }
        },
        [appendLog],
    );

    useFrame((_, dt) => {
        const body = rigidBodyRef.current;
        const fs = flightStateRef.current;

        if (!body || dt <= 0) {
            return;
        }

        const spec = drone.flight;
        const rotorMaxSpeed = drone.airframe.rotorMaxSpeed;

        if (!session.isRunning()) {
            // Idle: spool the rotors down (or keep them turning if the run
            // was aborted mid-air) and relax the visual tilt.
            const idleTarget = fs.airborne ? rotorMaxSpeed * 0.46 : 0;
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
                fs.rotorSpeed / rotorMaxSpeed,
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
        const gust = control.airborne && wind ? wind.gust(elapsed) : NO_GUST;

        appliedVelocity.x = step.linvel.x + gust.x;
        appliedVelocity.y = step.linvel.y + gust.y;
        appliedVelocity.z = step.linvel.z + gust.z;
        appliedSpin.y = step.angvel;

        body.setLinvel(appliedVelocity, true);
        body.setAngvel(appliedSpin, true);

        // --- Visual flight state: tilt, throttle, rotors, battery, HUD ---
        const previous = previousVelocityRef.current;
        const velocity = control.velocity;
        const accelX = (velocity.x - previous.x) / dt;
        const accelZ = (velocity.z - previous.z) / dt;
        previous.x = velocity.x;
        previous.y = velocity.y;
        previous.z = velocity.z;

        // The body's right-hand axis is the forward axis turned a quarter
        // turn, so it is two sign flips rather than a vector of its own.
        const forward = forwardVector(yaw);
        const rightX = -forward.z;
        const rightZ = forward.x;
        const forwardAccel =
            accelX * forward.x +
            accelZ * forward.z +
            DRAG_TILT_COEFFICIENT *
                (velocity.x * forward.x + velocity.z * forward.z);
        const rightAccel =
            accelX * rightX +
            accelZ * rightZ +
            DRAG_TILT_COEFFICIENT * (velocity.x * rightX + velocity.z * rightZ);

        const targetPitch = clamp(
            -Math.atan2(forwardAccel, GRAVITY),
            -spec.maxTilt,
            spec.maxTilt,
        );
        const targetRoll = clamp(
            -Math.atan2(rightAccel, GRAVITY),
            -spec.maxTilt,
            spec.maxTilt,
        );
        const smoothing = 1 - Math.exp(-dt / TILT_SMOOTHING_TAU);
        fs.pitch += (targetPitch - fs.pitch) * smoothing;
        fs.roll += (targetRoll - fs.roll) * smoothing;

        const tiltMagnitude = Math.hypot(fs.pitch, fs.roll);
        let throttle: number;

        if (bridge.active?.command.type === 'takeoff' && !control.airborne) {
            throttle =
                0.15 +
                0.45 * Math.min(1, bridge.active.elapsed / spec.spoolSeconds);
        } else if (control.airborne) {
            throttle = clamp(
                0.55 +
                    0.28 *
                        clamp(control.velocity.y / spec.maxClimbRate, -1, 1) +
                    0.18 * (tiltMagnitude / spec.maxTilt),
                0.15,
                1,
            );
        } else {
            throttle = 0.16;
        }

        fs.throttle = throttle;
        const rotorTarget = throttle * rotorMaxSpeed;
        const rotorDelta = rotorTarget - fs.rotorSpeed;
        fs.rotorSpeed +=
            Math.sign(rotorDelta) *
            Math.min(Math.abs(rotorDelta), ROTOR_SLEW_RATE * dt);

        fs.batteryPct = Math.max(
            0,
            fs.batteryPct -
                (spec.batteryIdleDrain + spec.batteryThrottleDrain * throttle) *
                    dt,
        );

        fs.armed = true;
        fs.airborne = control.airborne;
        fs.altitude = Math.max(0, position.y - spec.restHeight);
        fs.groundSpeed = Math.hypot(appliedVelocity.x, appliedVelocity.z);
        fs.verticalSpeed = appliedVelocity.y;
        fs.headingDeg = compassDegrees(yaw);
        fs.mode = modeLabel(bridge.active, control, fs.mode);

        droneEngine.setState(fs.throttle, fs.rotorSpeed, fs.groundSpeed);

        if (wind) {
            const alongX = Math.sin(wind.directionRad);
            const alongZ = -Math.cos(wind.directionRad);
            fs.windSpeed = Math.hypot(
                alongX * wind.meanSpeed + gust.x,
                alongZ * wind.meanSpeed + gust.z,
            );
            fs.windHeadingDeg = normalizeDegrees(
                (wind.directionRad * 180) / Math.PI,
            );
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
        } else if (finishedCommand.type === 'scan') {
            queryResult = scanEnvironment(
                environment,
                position,
                yaw,
                finishedCommand.range,
            );
        } else if (finishedCommand.type === 'takePhoto') {
            queryResult = capturePhoto(finishedCommand.label, position, yaw);
        }

        bridge.active.resolve(queryResult);
        bridge.active = null;
    });

    return { handleCollision, handleSensorEnter };
}
