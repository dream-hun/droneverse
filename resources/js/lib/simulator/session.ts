import { useSyncExternalStore } from 'react';
import type { RunResult } from '@/types/simulator';

export type ConsoleLine = { level: 'log' | 'warn' | 'error'; text: string };

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

    private listeners = new Set<() => void>();
    private controls: SimulatorControls | null = null;
    private running = false;
    private lines: ConsoleLine[] = [];
    private runResult: RunResult | null = null;

    subscribe = (listener: () => void): (() => void) => {
        this.listeners.add(listener);

        return () => {
            this.listeners.delete(listener);
        };
    };

    isRunning = (): boolean => this.running;

    logs = (): ConsoleLine[] => this.lines;

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
        this.running = true;
        this.lines = [];
        this.runResult = null;
        this.notify();
    }

    appendLog(line: ConsoleLine): void {
        const kept =
            this.lines.length >= SimulatorSession.MAX_LINES
                ? this.lines.slice(
                      this.lines.length - SimulatorSession.MAX_LINES + 1,
                  )
                : this.lines;

        this.lines = [...kept, line];
        this.notify();
    }

    /** Record the graded outcome of the run that just ended. */
    finish(result: RunResult): void {
        this.running = false;
        this.runResult = result;
        this.notify();
    }

    /** End the run without a result (user pressed Stop). */
    markStopped(): void {
        this.running = false;
        this.notify();
    }

    private notify(): void {
        this.listeners.forEach((listener) => listener());
    }
}

export function useSimulatorRunning(session: SimulatorSession): boolean {
    return useSyncExternalStore(session.subscribe, session.isRunning);
}

export function useSimulatorLogs(session: SimulatorSession): ConsoleLine[] {
    return useSyncExternalStore(session.subscribe, session.logs);
}

export function useSimulatorResult(
    session: SimulatorSession,
): RunResult | null {
    return useSyncExternalStore(session.subscribe, session.result);
}
