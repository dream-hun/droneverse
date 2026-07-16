import { useFrame } from '@react-three/fiber';
import { useRapier } from '@react-three/rapier';
import type { RapierRigidBody } from '@react-three/rapier';
import { useCallback, useEffect, useRef } from 'react';
import type { RefObject } from 'react';
import { postJson } from '@/lib/http';
import { createBridge } from '@/lib/simulator/commands';
import { gradeRun } from '@/lib/simulator/grader';
import {
    beginCommand,
    computeControlStep,
    forwardVector,
    REST_HEIGHT,
} from '@/lib/simulator/physics';
import type { SimulatorSession } from '@/lib/simulator/session';
import {
    advanceTelemetry,
    hasExceededTimeLimit,
} from '@/lib/simulator/telemetry';
import { SimulationWorkerClient } from '@/lib/simulator/worker-client';
import type { EnvironmentConfig, SuccessCriteria } from '@/types/simulator';

type UseDroneSimulationArgs = {
    rigidBodyRef: RefObject<RapierRigidBody | null>;
    session: SimulatorSession;
    environment: EnvironmentConfig;
    successCriteria: SuccessCriteria;
    maxScore: number;
    attemptUrl: string;
};

function currentYaw(body: RapierRigidBody): number {
    const rotation = body.rotation();

    return 2 * Math.atan2(rotation.y, rotation.w);
}

/**
 * Orchestrates one simulator run: the sandbox worker (via
 * SimulationWorkerClient), the per-frame physics control loop, telemetry,
 * grading, and result submission. Command execution flows worker → bridge →
 * physics step → resolve, so user code only advances when the drone has
 * physically finished each command. All UI-visible state is written to the
 * SimulatorSession store, which the page subscribes to from outside the
 * Canvas.
 */
export function useDroneSimulation({
    rigidBodyRef,
    session,
    environment,
    successCriteria,
    maxScore,
    attemptUrl,
}: UseDroneSimulationArgs) {
    const { world, rapier } = useRapier();
    const clientRef = useRef<SimulationWorkerClient | null>(null);
    const bridgeRef = useRef(createBridge(successCriteria.waypoints.length));
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
        session.markStopped();
    }, [session]);

    const finishRun = useCallback(
        (timedOut: boolean) => {
            // The time limit and the worker's "finished" message can land in
            // the same frame; only the first one may grade and submit.
            if (!session.isRunning()) {
                return;
            }

            clientRef.current?.terminate();
            clientRef.current = null;
            bridgeRef.current.active = null;
            bridgeRef.current.telemetry.timedOut = timedOut;

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
            resetDrone(body);

            const client = new SimulationWorkerClient({
                onCommand: (id, command) => {
                    const currentBody = rigidBodyRef.current;

                    if (!currentBody) {
                        return;
                    }

                    bridgeRef.current.active = {
                        ...beginCommand(
                            command,
                            currentBody.translation(),
                            currentYaw(currentBody),
                        ),
                        resolve: (result: unknown) =>
                            client.resolveCommand(id, result),
                    };
                },
                onLog: appendLog,
                onFinished: () => finishRun(false),
                onError: (message) => {
                    appendLog('error', [message]);
                    finishRun(false);
                },
            });

            clientRef.current = client;
            session.begin();
            client.start(code);
        },
        [
            appendLog,
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

    // Navigating away mid-run must not leave the sandbox worker alive.
    useEffect(
        () => () => {
            clientRef.current?.terminate();
            clientRef.current = null;
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

        if (!body || !session.isRunning()) {
            return;
        }

        const bridge = bridgeRef.current;
        const position = body.translation();

        advanceTelemetry(bridge, position, dt, successCriteria.waypoints);

        if (hasExceededTimeLimit(bridge, successCriteria.max_time_seconds)) {
            finishRun(true);

            return;
        }

        if (!bridge.active) {
            return;
        }

        const yaw = currentYaw(body);
        const step = computeControlStep(bridge.active, position, yaw, dt);

        body.setLinvel(step.linvel, true);
        body.setAngvel({ x: 0, y: step.angvel, z: 0 }, true);
        bridge.active.elapsed += dt;

        if (!step.done) {
            return;
        }

        const finishedCommand = bridge.active.command;
        let queryResult: unknown = null;

        if (finishedCommand.type === 'land') {
            bridge.telemetry.landed = true;
        } else if (finishedCommand.type === 'getPosition') {
            queryResult = { x: position.x, y: position.y, z: position.z };
        } else if (finishedCommand.type === 'getHeading') {
            queryResult = (yaw * 180) / Math.PI;
        } else if (finishedCommand.type === 'getAltitude') {
            queryResult = position.y;
        } else if (finishedCommand.type === 'getDistanceAhead') {
            const forward = forwardVector(yaw);
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
