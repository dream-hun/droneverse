import type {
    DroneCommand,
    MainToWorkerMessage,
    WorkerToMainMessage,
} from './commands';

/**
 * Runs entirely inside a dedicated Web Worker: no DOM and no `window`. Every
 * `drone.*` call is a round trip to the main thread, which only resolves it
 * once the physics loop has actually carried the command out.
 *
 * Workers *do* natively expose network APIs, so those are stubbed out below
 * before any user code runs. This is a guardrail for user programs that only
 * ever run in their author's own browser — not a hard security boundary.
 */
for (const api of ['fetch', 'XMLHttpRequest', 'WebSocket', 'EventSource']) {
    Object.defineProperty(self, api, {
        value: undefined,
        configurable: false,
        writable: false,
    });
}

let nextCommandId = 1;
const pending = new Map<
    number,
    { resolve: (value: unknown) => void; reject: (error: unknown) => void }
>();

function post(message: WorkerToMainMessage) {
    self.postMessage(message);
}

function sendCommand(command: DroneCommand): Promise<unknown> {
    const id = nextCommandId++;

    return new Promise((resolve, reject) => {
        pending.set(id, { resolve, reject });
        post({ kind: 'command', id, command });
    });
}

const drone = {
    takeoff: (altitude?: number) => sendCommand({ type: 'takeoff', altitude }),
    land: () => sendCommand({ type: 'land' }),
    moveForward: (distance: number) =>
        sendCommand({ type: 'moveForward', distance }),
    moveTo: (x: number, y: number, z: number) =>
        sendCommand({ type: 'moveTo', x, y, z }),
    turn: (degrees: number) => sendCommand({ type: 'turn', degrees }),
    hover: (seconds: number) => sendCommand({ type: 'hover', seconds }),
    setAltitude: (altitude: number) =>
        sendCommand({ type: 'setAltitude', altitude }),
    getPosition: () => sendCommand({ type: 'getPosition' }),
    getHeading: () => sendCommand({ type: 'getHeading' }),
    getAltitude: () => sendCommand({ type: 'getAltitude' }),
    getDistanceAhead: () => sendCommand({ type: 'getDistanceAhead' }),
};

function toSafeArgs(args: unknown[]): unknown[] {
    return args.map((arg) => {
        if (typeof arg === 'object' && arg !== null) {
            try {
                return JSON.parse(JSON.stringify(arg));
            } catch {
                return String(arg);
            }
        }

        return arg;
    });
}

const sandboxConsole = {
    log: (...args: unknown[]) =>
        post({ kind: 'log', level: 'log', args: toSafeArgs(args) }),
    warn: (...args: unknown[]) =>
        post({ kind: 'log', level: 'warn', args: toSafeArgs(args) }),
    error: (...args: unknown[]) =>
        post({ kind: 'log', level: 'error', args: toSafeArgs(args) }),
};

function createUserProgram(
    code: string,
): (drone: unknown, console: unknown) => Promise<unknown> {
    return new Function(
        'drone',
        'console',
        `"use strict";
        ${code}
        if (typeof main !== 'function') {
            throw new Error('Define an async function named "main(drone)" to control the drone.');
        }
        return main(drone);`,
    ) as (drone: unknown, console: unknown) => Promise<unknown>;
}

self.onmessage = async (event: MessageEvent<MainToWorkerMessage>) => {
    const message = event.data;

    if (message.kind === 'start') {
        try {
            const program = createUserProgram(message.code);
            await program(drone, sandboxConsole);
            post({ kind: 'finished' });
        } catch (error) {
            post({
                kind: 'error',
                message: error instanceof Error ? error.message : String(error),
            });
        }

        return;
    }

    if (message.kind === 'commandResult') {
        const entry = pending.get(message.id);

        if (entry) {
            pending.delete(message.id);
            entry.resolve(message.result);
        }
    }
};
