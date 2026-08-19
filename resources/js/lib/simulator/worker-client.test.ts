// @vitest-environment jsdom

import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import {
    crossOriginWorkerShim,
    SimulationWorkerClient,
} from '@/lib/simulator/worker-client';
import type { WorkerClientHandlers } from '@/lib/simulator/worker-client';

const APP_ORIGIN = 'https://droneverse.test';

describe('crossOriginWorkerShim', () => {
    it('leaves a same-origin script to the Worker constructor', () => {
        expect(
            crossOriginWorkerShim('/build/assets/worker-D4tA.js', APP_ORIGIN),
        ).toBeNull();

        expect(
            crossOriginWorkerShim(
                `${APP_ORIGIN}/build/assets/worker-D4tA.js`,
                APP_ORIGIN,
            ),
        ).toBeNull();
    });

    it('wraps the Vite dev server script, which the constructor would reject', () => {
        const script =
            'http://127.0.0.1:5173/resources/js/lib/simulator/worker.ts?worker_file&type=module';

        const shim = crossOriginWorkerShim(script, APP_ORIGIN);

        expect(shim).not.toBeNull();
        expect(shim).toContain(`import "${script}";`);
    });

    it('revokes the blob URL it is loaded from before importing anything', () => {
        const shim = crossOriginWorkerShim(
            'http://127.0.0.1:5173/worker.ts',
            APP_ORIGIN,
        );

        expect(shim).toContain('URL.revokeObjectURL(import.meta.url);');

        /*
         * Order is the whole point, and it is not cosmetic. Static imports
         * are hoisted, so the script is already being fetched before the
         * first statement runs — but a failed fetch abandons evaluation, and
         * everything below the import is then skipped. Revoking last means
         * leaking the blob in exactly the cases that produce one: a dev
         * server restarting mid-run, a CORS rejection, a worker terminated
         * before it finished loading.
         */
        expect(shim?.indexOf('URL.revokeObjectURL')).toBeLessThan(
            shim?.indexOf('import ') ?? -1,
        );
    });

    it('treats a port or scheme difference as cross-origin', () => {
        expect(
            crossOriginWorkerShim(`${APP_ORIGIN}:5173/worker.js`, APP_ORIGIN),
        ).not.toBeNull();

        expect(
            crossOriginWorkerShim(
                'http://droneverse.test/worker.js',
                APP_ORIGIN,
            ),
        ).not.toBeNull();
    });
});

/**
 * A worker that never loads anything, so the client's lifecycle can be driven
 * by hand. jsdom has no `Worker` at all, and the real one would need a dev
 * server on the other end.
 */
class FakeWorker {
    static last: FakeWorker | null = null;

    onmessage: ((event: MessageEvent) => void) | null = null;

    onerror: ((event: ErrorEvent) => void) | null = null;

    onmessageerror: (() => void) | null = null;

    terminated = false;

    constructor() {
        FakeWorker.last = this;
    }

    postMessage(): void {}

    terminate(): void {
        this.terminated = true;
    }
}

describe('SimulationWorkerClient', () => {
    let handlers: WorkerClientHandlers;

    beforeEach(() => {
        FakeWorker.last = null;
        vi.stubGlobal('Worker', FakeWorker);

        handlers = {
            onCommand: vi.fn(),
            onLog: vi.fn(),
            onFinished: vi.fn(),
            onError: vi.fn(),
        };
    });

    afterEach(() => {
        vi.unstubAllGlobals();
    });

    /**
     * The failure this exists for. `new Worker(new URL(...))` threw
     * synchronously out of the caller's `run()`; booting from a blob never
     * throws, so every remaining failure — a dev server restarting, a CORS
     * rejection, a CDN host that does not allow the app's origin — arrives
     * asynchronously as an `error` event instead. Unhandled, the run never
     * ends: the session stays running, the drone stays armed and the console
     * stays empty, which is indistinguishable from Run doing nothing at all.
     */
    it('ends the run when the worker fails to start', () => {
        new SimulationWorkerClient(handlers);

        FakeWorker.last?.onerror?.(
            new ErrorEvent('error', { message: 'Failed to fetch module' }),
        );

        expect(handlers.onError).toHaveBeenCalledWith('Failed to fetch module');
        expect(handlers.onFinished).not.toHaveBeenCalled();
    });

    it('reports a failure even when the error event carries no message', () => {
        new SimulationWorkerClient(handlers);

        FakeWorker.last?.onerror?.(new ErrorEvent('error'));

        expect(handlers.onError).toHaveBeenCalledWith(
            expect.stringContaining('failed to start'),
        );
    });

    // A message the structured clone algorithm could not deliver strands the
    // run exactly as an unhandled error would.
    it('ends the run on an undeliverable message', () => {
        new SimulationWorkerClient(handlers);

        FakeWorker.last?.onmessageerror?.();

        expect(handlers.onError).toHaveBeenCalledOnce();
    });

    it('still routes the messages it was already routing', () => {
        new SimulationWorkerClient(handlers);

        FakeWorker.last?.onmessage?.(
            new MessageEvent('message', { data: { kind: 'finished' } }),
        );

        expect(handlers.onFinished).toHaveBeenCalledOnce();
        expect(handlers.onError).not.toHaveBeenCalled();
    });

    it('terminates the worker it started', () => {
        const client = new SimulationWorkerClient(handlers);

        client.terminate();

        expect(FakeWorker.last?.terminated).toBe(true);
    });
});
