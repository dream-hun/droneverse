export type DroneCommand =
    | { type: 'takeoff'; altitude?: number }
    | { type: 'land' }
    | { type: 'moveForward'; distance: number }
    | { type: 'moveTo'; x: number; y: number; z: number }
    | { type: 'turn'; degrees: number }
    | { type: 'hover'; seconds: number }
    | { type: 'setAltitude'; altitude: number }
    | { type: 'getPosition' }
    | { type: 'getHeading' }
    | { type: 'getAltitude' }
    | { type: 'getDistanceAhead' };

/** Messages sent from the sandboxed worker to the main thread. */
export type WorkerToMainMessage =
    | { kind: 'command'; id: number; command: DroneCommand }
    | { kind: 'log'; level: 'log' | 'warn' | 'error'; args: unknown[] }
    | { kind: 'finished' }
    | { kind: 'error'; message: string };

/** Messages sent from the main thread to the sandboxed worker. */
export type MainToWorkerMessage =
    | { kind: 'start'; code: string }
    | { kind: 'commandResult'; id: number; result: unknown };

export type Vector3 = { x: number; y: number; z: number };

/** A command currently being carried out by the physics loop. */
export type ActiveCommand = {
    command: DroneCommand;
    elapsed: number;
    startPosition: Vector3;
    startYaw: number;
    targetPosition?: Vector3;
    targetYaw?: number;
    hoverSeconds?: number;
    resolve: (result: unknown) => void;
};

/** Aggregated outcome of a run, computed incrementally as the physics loop advances. */
export type RunTelemetry = {
    waypointsHit: number;
    waypointsTotal: number;
    collisions: number;
    maxAltitude: number;
    landed: boolean;
    elapsedSeconds: number;
    timedOut: boolean;
};

/** Mutable state shared between the React hook and the per-frame physics step. */
export type SimulationBridge = {
    active: ActiveCommand | null;
    nextWaypointIndex: number;
    telemetry: RunTelemetry;
};

export function createBridge(waypointsTotal: number): SimulationBridge {
    return {
        active: null,
        nextWaypointIndex: 0,
        telemetry: {
            waypointsHit: 0,
            waypointsTotal,
            collisions: 0,
            maxAltitude: 0,
            landed: false,
            elapsedSeconds: 0,
            timedOut: false,
        },
    };
}
