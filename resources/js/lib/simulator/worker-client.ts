import type {
    DroneCommand,
    MainToWorkerMessage,
    WorkerToMainMessage,
} from './commands';

export type WorkerClientHandlers = {
    onCommand: (id: number, command: DroneCommand) => void;
    onLog: (level: 'log' | 'warn' | 'error', args: unknown[]) => void;
    onFinished: () => void;
    onError: (message: string) => void;
};

/**
 * Typed lifecycle wrapper around the sandbox Web Worker. Owns the raw
 * `postMessage` protocol on the main-thread side so callers never touch
 * untyped messages; one instance corresponds to exactly one run.
 */
export class SimulationWorkerClient {
    private readonly worker: Worker;

    constructor(handlers: WorkerClientHandlers) {
        this.worker = new Worker(new URL('./worker.ts', import.meta.url), {
            type: 'module',
        });

        this.worker.onmessage = (event: MessageEvent<WorkerToMainMessage>) => {
            const message = event.data;

            switch (message.kind) {
                case 'command':
                    handlers.onCommand(message.id, message.command);
                    break;
                case 'log':
                    handlers.onLog(message.level, message.args);
                    break;
                case 'finished':
                    handlers.onFinished();
                    break;
                case 'error':
                    handlers.onError(message.message);
                    break;
            }
        };
    }

    start(code: string): void {
        this.post({ kind: 'start', code });
    }

    resolveCommand(id: number, result: unknown): void {
        this.post({ kind: 'commandResult', id, result });
    }

    terminate(): void {
        this.worker.terminate();
    }

    private post(message: MainToWorkerMessage): void {
        this.worker.postMessage(message);
    }
}
