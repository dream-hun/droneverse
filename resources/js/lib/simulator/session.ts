import { useSyncExternalStore } from 'react';
import type { RunResult } from '@/types/simulator';

export type ConsoleLine = { level: 'log' | 'warn' | 'error'; text: string };

/**
 * A console line once the session has filed it.
 *
 * The `id` is what lets the panel key its rows on the line rather than on
 * its position. The buffer is a sliding window, so once it is full every
 * subsequent line shifts every index behind it — keyed by index, React sees
 * all five hundred rows change and rewrites the whole list for each new
 * line. Keyed by id it sees one row leave and one arrive.
 */
export type ConsoleEntry = ConsoleLine & { id: number };

/** Imperative run/stop hooks registered by the physics layer inside the Canvas. */
export type SimulatorControls = {
    run: (code: string) => void;
    stop: () => void;
};

/**
 * Single source of truth for one simulator run's UI-visible state.
 *
 * The physics loop must live inside the R3F <Canvas> (it needs useFrame and
 * useRapier), while the toolbar, editor, and result modal live outside it.
 * Holding the shared state in an external store lets both sides read and
 * write it directly — no setter props threaded through the canvas tree and
 * no useEffect mirroring — and lets each consumer subscribe to only the
 * slice it renders, so a chatty console can't re-render the whole page.
 *
 * Snapshots are replaced, never mutated, so useSyncExternalStore's
 * Object.is bail-out works per slice.
 */
export class SimulatorSession {
    /**
     * Console lines kept before the oldest start scrolling off.
     *
     * The program producing these lines is written by the pilot, so a stray
     * `console.log` inside a flight loop can emit thousands per run. Holding
     * every one of them would grow the array — and the work of re-rendering
     * it — without bound, for output nobody can read anyway.
     */
    private static readonly MAX_LINES = 500;

    /**
     * Length at which the write buffer is trimmed back to MAX_LINES.
     *
     * Trimming on every line past the cap would pay an O(MAX_LINES) shift per
     * line. Letting a whole extra window accumulate first pays the same shift
     * once per MAX_LINES lines instead, which is O(1) amortised. Only
     * `lines` is ever read, and that is trimmed exactly on publish, so the
     * slack here is never visible.
     */
    private static readonly TRIM_AT = 1000;

    /**
     * How long a batch may wait when no frame arrives to publish it.
     *
     * requestAnimationFrame does not fire in a hidden tab, and a run can keep
     * logging there, so the frame alone cannot be the only path to a publish.
     */
    private static readonly HIDDEN_FLUSH_MS = 100;

    private listeners = new Set<() => void>();
    private controls: SimulatorControls | null = null;
    private running = false;

    /**
     * The published snapshot: the only log state `getSnapshot` may observe.
     *
     * Reassigned solely by `publishLogs` and `begin`, each of which is
     * followed by a notify, so React never sees this move without being told.
     */
    private lines: ConsoleEntry[] = [];

    /**
     * The write side, mutated freely by `appendLog`.
     *
     * Kept separate from `lines` because useSyncExternalStore requires a
     * snapshot to stay put between notifications: batching repaints while
     * `getSnapshot` still returned live writes let React observe a change it
     * had not been notified of, which it resolves by forcing a synchronous
     * re-render — spending the renders the batching was meant to save.
     */
    private buffer: ConsoleEntry[] = [];

    /** Whether `buffer` holds anything `lines` has not been given yet. */
    private logsDirty = false;
    private nextLineId = 0;
    private pendingLogFrame: number | null = null;
    private pendingLogTimer: ReturnType<typeof setTimeout> | null = null;
    private runResult: RunResult | null = null;

    subscribe = (listener: () => void): (() => void) => {
        this.listeners.add(listener);

        return () => {
            this.listeners.delete(listener);
        };
    };

    isRunning = (): boolean => this.running;

    logs = (): ConsoleEntry[] => this.lines;

    result = (): RunResult | null => this.runResult;

    /** Start a run; a no-op until the canvas has registered its controls. */
    run(code: string): void {
        this.controls?.run(code);
    }

    /** Abort the current run; a no-op when nothing is registered. */
    stop(): void {
        this.controls?.stop();
    }

    clearResult(): void {
        this.runResult = null;
        this.notify();
    }

    registerControls(controls: SimulatorControls): () => void {
        this.controls = controls;

        return () => {
            if (this.controls === controls) {
                this.controls = null;
            }
        };
    }

    /** Reset per-run state as a new run starts. */
    begin(): void {
        this.cancelScheduledPublish();
        this.running = true;
        this.lines = [];
        this.buffer = [];
        this.logsDirty = false;
        this.runResult = null;
        this.notify();
    }

    /**
     * File a console line, and repaint at most once per frame.
     *
     * The program producing these lines is the pilot's, and the worker posts
     * one message per `console.log`, so the arrival rate is whatever their
     * code does — a log inside a flight loop delivers thousands of separate
     * messages. Notifying on each one made that a re-render each, so the
     * cost of a chatty run was paid in dropped frames on the very loop the
     * pilot was trying to watch. MAX_LINES already bounds what is *kept*;
     * this bounds how often what is kept is drawn.
     *
     * Batching only ever delays a repaint, never drops one: every lifecycle
     * transition publishes what is buffered before it notifies, and a timer
     * backs up the frame for tabs where frames stop arriving.
     */
    appendLog(line: ConsoleLine): void {
        this.buffer.push({ ...line, id: this.nextLineId++ });
        this.logsDirty = true;

        if (this.buffer.length >= SimulatorSession.TRIM_AT) {
            this.buffer = this.buffer.slice(
                this.buffer.length - SimulatorSession.MAX_LINES,
            );
        }

        this.scheduleLogPublish();
    }

    /** Record the graded outcome of the run that just ended. */
    finish(result: RunResult): void {
        this.publishLogs();
        this.running = false;
        this.runResult = result;
        this.notify();
    }

    /** End the run without a result (user pressed Stop). */
    markStopped(): void {
        this.publishLogs();
        this.running = false;
        this.notify();
    }

    /**
     * Arrange for buffered lines to be published, if that is not already
     * arranged.
     *
     * The frame and the timer are not duplicates of each other: the frame
     * paces publishing to the display while the tab is visible, and the timer
     * is what still fires when it is not, or where there is no frame loop at
     * all. Whichever runs first publishes and cancels the other.
     */
    private scheduleLogPublish(): void {
        if (this.pendingLogFrame !== null || this.pendingLogTimer !== null) {
            return;
        }

        this.pendingLogTimer = setTimeout(() => {
            this.publishLogs();
            this.notify();
        }, SimulatorSession.HIDDEN_FLUSH_MS);

        if (typeof requestAnimationFrame === 'function') {
            this.pendingLogFrame = requestAnimationFrame(() => {
                this.publishLogs();
                this.notify();
            });
        }
    }

    /**
     * Move buffered lines into the snapshot, trimmed to the window.
     *
     * Leaves notifying to the caller so each path notifies exactly once, and
     * leaves `lines` alone when nothing was buffered, so a lifecycle notify
     * that published no new output still lets the log slice bail out on
     * Object.is rather than re-rendering the panel on an identical list.
     */
    private publishLogs(): void {
        this.cancelScheduledPublish();

        if (!this.logsDirty) {
            return;
        }

        this.logsDirty = false;

        if (this.buffer.length > SimulatorSession.MAX_LINES) {
            this.buffer = this.buffer.slice(
                this.buffer.length - SimulatorSession.MAX_LINES,
            );
        }

        this.lines = this.buffer.slice();
    }

    /** Drop any queued publish without performing it. */
    private cancelScheduledPublish(): void {
        if (this.pendingLogFrame !== null) {
            cancelAnimationFrame(this.pendingLogFrame);
            this.pendingLogFrame = null;
        }

        if (this.pendingLogTimer !== null) {
            clearTimeout(this.pendingLogTimer);
            this.pendingLogTimer = null;
        }
    }

    private notify(): void {
        this.listeners.forEach((listener) => listener());
    }
}

/**
 * A run only ever exists in the browser, so the server snapshot of every
 * slice below is its pre-run value. They are passed explicitly because
 * useSyncExternalStore throws without one the moment a component holding it
 * is server-rendered, and `inertia.ssr.enabled` is on.
 */
const NO_LOGS: ConsoleEntry[] = [];

export function useSimulatorRunning(session: SimulatorSession): boolean {
    return useSyncExternalStore(
        session.subscribe,
        session.isRunning,
        () => false,
    );
}

export function useSimulatorLogs(session: SimulatorSession): ConsoleEntry[] {
    return useSyncExternalStore(session.subscribe, session.logs, () => NO_LOGS);
}

export function useSimulatorResult(
    session: SimulatorSession,
): RunResult | null {
    return useSyncExternalStore(session.subscribe, session.result, () => null);
}
