import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { SimulatorSession } from '@/lib/simulator/session';
import type { RunResult } from '@/types/simulator';

/** Mirrors of the store's private constants, so drift in either shows up here. */
const MAX_LINES = 500;
const TRIM_AT = 1000;
const HIDDEN_FLUSH_MS = 100;

/**
 * A hand-driven requestAnimationFrame.
 *
 * The store's whole contract is about *when* buffered lines become visible,
 * so the frame has to be something the test advances deliberately rather
 * than something it waits for. Fake timers are scoped to setTimeout for the
 * same reason: the timer fallback and the frame are separate paths here, and
 * faking both through one clock would hide which one did the publishing.
 */
function installFrames() {
    let nextHandle = 1;
    const callbacks = new Map<number, FrameRequestCallback>();

    vi.stubGlobal('requestAnimationFrame', (callback: FrameRequestCallback) => {
        const handle = nextHandle++;
        callbacks.set(handle, callback);

        return handle;
    });

    vi.stubGlobal('cancelAnimationFrame', (handle: number) => {
        callbacks.delete(handle);
    });

    return {
        run(): void {
            const due = [...callbacks.values()];
            callbacks.clear();
            due.forEach((callback) => callback(0));
        },
        pending: (): number => callbacks.size,
    };
}

function runResult(overrides: Partial<RunResult> = {}): RunResult {
    return {
        completed: true,
        score: 100,
        stars: 3,
        objectivesHit: 2,
        objectivesTotal: 2,
        waypointsHit: 2,
        waypointsTotal: 2,
        collisions: 0,
        landed: true,
        elapsedSeconds: 12,
        timedOut: false,
        photosTaken: 0,
        photoTargetsHit: 0,
        photoTargetsTotal: 0,
        photosMissing: 0,
        washRequired: false,
        washed: false,
        ...overrides,
    };
}

function appendLines(session: SimulatorSession, count: number): void {
    for (let index = 0; index < count; index += 1) {
        session.appendLog({ level: 'log', text: `line ${index}` });
    }
}

describe('SimulatorSession', () => {
    let session: SimulatorSession;
    let frames: ReturnType<typeof installFrames>;

    beforeEach(() => {
        vi.useFakeTimers({ toFake: ['setTimeout', 'clearTimeout'] });
        frames = installFrames();
        session = new SimulatorSession();
        session.begin();
    });

    afterEach(() => {
        vi.useRealTimers();
        vi.unstubAllGlobals();
    });

    describe('the console window', () => {
        it('keeps every line up to the cap', () => {
            appendLines(session, MAX_LINES);
            frames.run();

            const logs = session.logs();

            expect(logs).toHaveLength(MAX_LINES);
            expect(logs[0].id).toBe(0);
            expect(logs[MAX_LINES - 1].id).toBe(MAX_LINES - 1);
        });

        it('drops the oldest line once past the cap', () => {
            appendLines(session, MAX_LINES + 1);
            frames.run();

            const logs = session.logs();

            expect(logs).toHaveLength(MAX_LINES);
            expect(logs[0].id).toBe(1);
            expect(logs[MAX_LINES - 1].id).toBe(MAX_LINES);
        });

        /**
         * The buffer is allowed to run past the cap between publishes so the
         * trim is amortised. These pin the arithmetic on both sides of that
         * threshold, which is where an off-by-one would silently eat a line.
         */
        it('holds the right window just below the trim threshold', () => {
            appendLines(session, TRIM_AT - 1);
            frames.run();

            const logs = session.logs();

            expect(logs).toHaveLength(MAX_LINES);
            expect(logs[0].id).toBe(TRIM_AT - 1 - MAX_LINES);
            expect(logs[MAX_LINES - 1].id).toBe(TRIM_AT - 2);
        });

        it('holds the right window exactly at the trim threshold', () => {
            appendLines(session, TRIM_AT);
            frames.run();

            const logs = session.logs();

            expect(logs).toHaveLength(MAX_LINES);
            expect(logs[0].id).toBe(TRIM_AT - MAX_LINES);
            expect(logs[MAX_LINES - 1].id).toBe(TRIM_AT - 1);
        });

        it('holds the right window just past the trim threshold', () => {
            appendLines(session, TRIM_AT + 1);
            frames.run();

            const logs = session.logs();

            expect(logs).toHaveLength(MAX_LINES);
            expect(logs[0].id).toBe(TRIM_AT + 1 - MAX_LINES);
            expect(logs[MAX_LINES - 1].id).toBe(TRIM_AT);
        });

        it('holds the window across many trim cycles', () => {
            appendLines(session, 5000);
            frames.run();

            const logs = session.logs();

            expect(logs).toHaveLength(MAX_LINES);
            expect(logs[0].id).toBe(5000 - MAX_LINES);
            expect(logs[MAX_LINES - 1].id).toBe(4999);
        });

        it('gives every surviving line a unique, ordered id', () => {
            appendLines(session, TRIM_AT + 200);
            frames.run();

            const ids = session.logs().map((line) => line.id);

            expect(new Set(ids).size).toBe(ids.length);
            expect(ids).toEqual([...ids].sort((a, b) => a - b));
        });

        it('does not reuse ids across runs', () => {
            appendLines(session, 2);
            frames.run();
            const firstRunLastId = session.logs()[1].id;

            session.begin();
            appendLines(session, 2);
            frames.run();

            expect(session.logs()[0].id).toBeGreaterThan(firstRunLastId);
        });

        it('preserves level and text through the window', () => {
            session.appendLog({ level: 'warn', text: 'low battery' });
            session.appendLog({ level: 'error', text: 'crashed' });
            frames.run();

            expect(session.logs()).toEqual([
                { level: 'warn', text: 'low battery', id: 0 },
                { level: 'error', text: 'crashed', id: 1 },
            ]);
        });
    });

    describe('snapshot stability', () => {
        it('does not move the snapshot between notifications', () => {
            const before = session.logs();

            appendLines(session, 50);

            expect(session.logs()).toBe(before);
            expect(session.logs()).toHaveLength(0);

            frames.run();

            expect(session.logs()).not.toBe(before);
            expect(session.logs()).toHaveLength(50);
        });

        /**
         * The regression this store was rewritten for: useSyncExternalStore
         * re-reads getSnapshot when anything else notifies, and React treats a
         * snapshot that moved without a notification as tearing — which it
         * resolves by forcing a synchronous re-render, spending exactly the
         * renders the batching exists to save.
         */
        it('holds the snapshot still when an unrelated slice notifies', () => {
            const before = session.logs();
            appendLines(session, 10);

            const listener = vi.fn();
            session.subscribe(listener);
            session.clearResult();

            expect(listener).toHaveBeenCalledTimes(1);
            expect(session.logs()).toBe(before);
        });

        it('notifies once per frame however many lines arrived', () => {
            const listener = vi.fn();
            session.subscribe(listener);

            appendLines(session, TRIM_AT);

            expect(listener).not.toHaveBeenCalled();

            frames.run();

            expect(listener).toHaveBeenCalledTimes(1);
        });

        it('does not mint a new snapshot when nothing was buffered', () => {
            const before = session.logs();

            session.finish(runResult());

            expect(session.logs()).toBe(before);
        });

        it('stops notifying a listener once it unsubscribes', () => {
            const listener = vi.fn();
            const unsubscribe = session.subscribe(listener);

            unsubscribe();
            appendLines(session, 3);
            frames.run();

            expect(listener).not.toHaveBeenCalled();
        });
    });

    describe('lifecycle flushes', () => {
        it('publishes buffered lines when the run finishes', () => {
            appendLines(session, 3);

            expect(session.logs()).toHaveLength(0);

            const result = runResult();
            session.finish(result);

            expect(session.logs()).toHaveLength(3);
            expect(session.isRunning()).toBe(false);
            expect(session.result()).toBe(result);
        });

        it('publishes buffered lines when the run is stopped', () => {
            appendLines(session, 4);

            session.markStopped();

            expect(session.logs()).toHaveLength(4);
            expect(session.isRunning()).toBe(false);
            expect(session.result()).toBeNull();
        });

        it('publishes a trimmed window when a burst never met a frame', () => {
            appendLines(session, TRIM_AT + 1);

            session.finish(runResult());

            const logs = session.logs();

            expect(logs).toHaveLength(MAX_LINES);
            expect(logs[MAX_LINES - 1].id).toBe(TRIM_AT);
        });

        it('drops the queued publish once it has flushed', () => {
            appendLines(session, 3);

            const listener = vi.fn();
            session.subscribe(listener);
            session.finish(runResult());

            expect(listener).toHaveBeenCalledTimes(1);
            expect(frames.pending()).toBe(0);

            frames.run();
            vi.advanceTimersByTime(HIDDEN_FLUSH_MS * 10);

            expect(listener).toHaveBeenCalledTimes(1);
        });

        it('never surfaces lines buffered by a previous run', () => {
            appendLines(session, 10);

            session.begin();

            expect(session.logs()).toHaveLength(0);

            frames.run();
            vi.advanceTimersByTime(HIDDEN_FLUSH_MS * 10);

            expect(session.logs()).toHaveLength(0);
        });
    });

    describe('publishing without a frame loop', () => {
        it('falls back to a timer when frames stop arriving', () => {
            const listener = vi.fn();
            session.subscribe(listener);

            appendLines(session, 5);

            expect(session.logs()).toHaveLength(0);

            vi.advanceTimersByTime(HIDDEN_FLUSH_MS);

            expect(session.logs()).toHaveLength(5);
            expect(listener).toHaveBeenCalledTimes(1);
            expect(frames.pending()).toBe(0);
        });

        it('lets the frame win, and cancels the timer behind it', () => {
            const listener = vi.fn();
            session.subscribe(listener);

            appendLines(session, 5);
            frames.run();

            expect(listener).toHaveBeenCalledTimes(1);

            vi.advanceTimersByTime(HIDDEN_FLUSH_MS * 10);

            expect(listener).toHaveBeenCalledTimes(1);
            expect(session.logs()).toHaveLength(5);
        });

        it('publishes where requestAnimationFrame does not exist at all', () => {
            vi.stubGlobal('requestAnimationFrame', undefined);

            const headless = new SimulatorSession();
            headless.begin();
            appendLines(headless, 3);

            expect(headless.logs()).toHaveLength(0);

            vi.advanceTimersByTime(HIDDEN_FLUSH_MS);

            expect(headless.logs()).toHaveLength(3);
        });
    });
});
