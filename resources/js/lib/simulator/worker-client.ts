import type {
    DroneCommand,
    MainToWorkerMessage,
    WorkerToMainMessage,
} from './commands';
import workerScriptUrl from './worker?worker&url';

export type WorkerClientHandlers = {
    onCommand: (id: number, command: DroneCommand) => void;
    onLog: (level: 'log' | 'warn' | 'error', args: unknown[]) => void;
    onFinished: () => void;
    onError: (message: string) => void;
};

/**
 * The source of a module that does nothing but load `script`, for when the
 * script cannot be handed to `new Worker()` directly. Returns null when it
 * can, which is every same-origin case.
 *
 * A worker's script has to be same-origin with the page that starts it, and
 * in development it is not: Vite serves every module from its own origin
 * (`http://127.0.0.1:5173`) while the app is served from the site's
 * (`https://droneverse.test`), so the constructor throws a SecurityError and
 * pressing Run does nothing at all.
 *
 * A blob URL inherits the origin of the document that created it, so a
 * one-line module wrapped in a blob passes the same-origin check, and its
 * import of the real script is then an ordinary cross-origin module fetch —
 * which the dev server answers, laravel-vite-plugin having allowed the app's
 * origin in its CORS config.
 *
 * The revoke comes *before* the import, which is the order Vite uses for its
 * own inlined workers and the only order that always runs. Static imports are
 * hoisted, so the script is already being fetched by the time the first
 * statement executes — but if that fetch fails, module evaluation is
 * abandoned and anything below the import never runs at all. A revoke placed
 * last is therefore skipped in exactly the cases that produce a leak: a dev
 * server restarting mid-run, a CORS rejection, a worker terminated before it
 * finished loading. {@see SimulationWorkerClient} revokes from the main
 * thread as well, for the case where the module never evaluates.
 *
 * Built assets are served from the app's own origin, so production takes the
 * direct path; a build served from a CDN would take the shim, and needs that
 * host to allow the app's origin like the dev server does.
 */
export function crossOriginWorkerShim(
    script: string,
    pageOrigin: string,
): string | null {
    const url = new URL(script, pageOrigin);

    if (url.origin === pageOrigin) {
        return null;
    }

    return `URL.revokeObjectURL(import.meta.url);\nimport ${JSON.stringify(url.href)};\n`;
}

/**
 * The worker, plus the blob URL it was booted from when there was one, so the
 * caller can revoke a URL whose module never got far enough to revoke itself.
 */
type SandboxWorker = {
    worker: Worker;
    blobUrl: string | null;
};

function createSandboxWorker(): SandboxWorker {
    const shim = crossOriginWorkerShim(workerScriptUrl, self.location.origin);

    if (shim === null) {
        return {
            worker: new Worker(workerScriptUrl, { type: 'module' }),
            blobUrl: null,
        };
    }

    const blob = new Blob([shim], { type: 'text/javascript' });
    const blobUrl = URL.createObjectURL(blob);

    return { worker: new Worker(blobUrl, { type: 'module' }), blobUrl };
}

/**
 * Typed lifecycle wrapper around the sandbox Web Worker. Owns the raw
 * `postMessage` protocol on the main-thread side so callers never touch
 * untyped messages; one instance corresponds to exactly one run.
 */
export class SimulationWorkerClient {
    private readonly worker: Worker;

    private blobUrl: string | null;

    constructor(handlers: WorkerClientHandlers) {
        const sandbox = createSandboxWorker();

        this.worker = sandbox.worker;
        this.blobUrl = sandbox.blobUrl;

        /*
         * A worker that dies on its way up has to end the run, because
         * nothing else will.
         *
         * This used to be the constructor's job: `new Worker(new URL(...))`
         * threw a SecurityError synchronously, out of the caller's `run()`.
         * Booting from a blob never throws — the blob is always a valid
         * same-origin module — so every remaining failure is asynchronous and
         * arrives here instead: the cross-origin import of the real script
         * fails whenever the dev server is restarting, whenever CORS turns
         * the app's origin away, and on any CDN build whose host does not
         * allow it.
         *
         * Unhandled, that is a run that never ends. `onFinished` and
         * `onError` are the only two ways the caller learns a run is over, so
         * without this the session stays running, the drone stays armed, the
         * engine keeps playing and the console shows nothing — the same
         * "pressing Run does nothing" the shim above exists to fix, reached
         * from the other side.
         */
        this.worker.onerror = (event: ErrorEvent) => {
            this.revokeBlobUrl();
            handlers.onError(
                event.message || 'The simulator sandbox failed to start.',
            );
        };

        // A message the structured clone algorithm could not deliver. It
        // cannot be acted on, and swallowing it would strand the run exactly
        // as an unhandled error would.
        this.worker.onmessageerror = () => {
            handlers.onError(
                'The simulator sandbox sent an unreadable message.',
            );
        };

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
        this.revokeBlobUrl();
    }

    /**
     * Release the blob the worker was booted from, if it has not released
     * itself.
     *
     * The shim revokes its own URL as its first statement, so on the happy
     * path this finds nothing to do. It matters when the module never
     * evaluated — a failed import, or a terminate that landed first — which
     * is the case that would otherwise leak one blob URL per run for the
     * lifetime of the document. Revoking twice is harmless; revoking a URL
     * the worker is still loading from is not, which is why this only runs
     * once the worker is finished with.
     */
    private revokeBlobUrl(): void {
        if (this.blobUrl === null) {
            return;
        }

        URL.revokeObjectURL(this.blobUrl);
        this.blobUrl = null;
    }

    private post(message: MainToWorkerMessage): void {
        this.worker.postMessage(message);
    }
}
