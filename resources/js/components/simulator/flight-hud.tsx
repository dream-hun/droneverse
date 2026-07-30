import { useEffect, useState } from 'react';
import type { FlightVisualState } from '@/lib/simulator/flight-state';
import { cn } from '@/lib/utils';

type HudSnapshot = {
    mode: string;
    altitude: number;
    groundSpeed: number;
    verticalSpeed: number;
    headingDeg: number;
    batteryPct: number;
    windSpeed: number;
    windHeadingDeg: number;
    photosTaken: number;
};

function takeSnapshot(flightState: FlightVisualState): HudSnapshot {
    return {
        mode: flightState.mode,
        altitude: flightState.altitude,
        groundSpeed: flightState.groundSpeed,
        verticalSpeed: flightState.verticalSpeed,
        headingDeg: flightState.headingDeg,
        batteryPct: flightState.batteryPct,
        windSpeed: flightState.windSpeed,
        windHeadingDeg: flightState.windHeadingDeg,
        photosTaken: flightState.photosTaken,
    };
}

/**
 * Whether two snapshots would draw the same strip.
 *
 * Compared at the precision the readouts are *printed* at, not the
 * precision they are held at. Altitude is rendered to one decimal and
 * heading to a whole degree, so a hovering drone whose altitude wanders by
 * a ten-thousandth of a metre produces an identical strip — and the poll
 * below turns that into a re-render only when it would show.
 */
function isSameReadout(a: HudSnapshot, b: HudSnapshot): boolean {
    return (
        a.mode === b.mode &&
        a.photosTaken === b.photosTaken &&
        a.altitude.toFixed(1) === b.altitude.toFixed(1) &&
        a.groundSpeed.toFixed(1) === b.groundSpeed.toFixed(1) &&
        a.verticalSpeed.toFixed(1) === b.verticalSpeed.toFixed(1) &&
        a.windSpeed.toFixed(1) === b.windSpeed.toFixed(1) &&
        Math.round(a.headingDeg) === Math.round(b.headingDeg) &&
        Math.round(a.windHeadingDeg) === Math.round(b.windHeadingDeg) &&
        Math.round(a.batteryPct) === Math.round(b.batteryPct)
    );
}

function modeChipClass(mode: string): string {
    if (mode === 'STANDBY') {
        return 'text-slate-300';
    }

    if (mode === 'LANDED') {
        return 'text-emerald-300';
    }

    if (mode === 'SPOOL UP' || mode === 'LANDING') {
        return 'text-amber-300';
    }

    return 'text-sky-300';
}

function batteryBarClass(batteryPct: number): string {
    if (batteryPct > 50) {
        return 'bg-emerald-400';
    }

    if (batteryPct > 25) {
        return 'bg-amber-400';
    }

    return 'bg-red-500';
}

/**
 * DJI-style telemetry strip over the 3D view. Polls the mutable flight
 * state at 10 Hz — plenty for numeric readouts without re-rendering the
 * page at frame rate.
 *
 * The poll is a poll, not a change notification: the flight state is
 * mutated in place by the physics loop and says nothing when it moves. So
 * the snapshot it produces is kept only when it would actually change what
 * is on screen. Handed to `setState` unconditionally, a fresh object every
 * 100 ms was ten renders a second for the whole strip — for the entire time
 * the page was open, including the STANDBY drone sitting on its pad before
 * anyone has written a line of code.
 */
export function FlightHud({ flightState }: { flightState: FlightVisualState }) {
    const [snapshot, setSnapshot] = useState<HudSnapshot>(() =>
        takeSnapshot(flightState),
    );

    useEffect(() => {
        const id = window.setInterval(() => {
            setSnapshot((previous) => {
                const next = takeSnapshot(flightState);

                // Returning the identical object is React's own bail-out:
                // no re-render is scheduled at all.
                return isSameReadout(previous, next) ? previous : next;
            });
        }, 100);

        return () => window.clearInterval(id);
    }, [flightState]);

    const chip =
        'pointer-events-none rounded-md border border-white/10 bg-slate-950/70 px-2 py-1 text-slate-100 backdrop-blur';

    return (
        <div className="absolute inset-x-2 bottom-2 z-10 flex flex-wrap items-center gap-1.5 font-mono text-[11px]">
            <span
                className={cn(
                    chip,
                    'font-semibold',
                    modeChipClass(snapshot.mode),
                )}
            >
                {snapshot.mode}
            </span>
            <span className={chip}>ALT {snapshot.altitude.toFixed(1)}m</span>
            <span className={chip}>SPD {snapshot.groundSpeed.toFixed(1)}</span>
            <span className={chip}>
                VS {snapshot.verticalSpeed >= 0 ? '+' : ''}
                {snapshot.verticalSpeed.toFixed(1)}
            </span>
            <span className={chip}>
                HDG {String(Math.round(snapshot.headingDeg)).padStart(3, '0')}°
            </span>
            <span className={chip}>
                WIND {snapshot.windSpeed.toFixed(1)}
                <span
                    className="ml-1 inline-block"
                    style={{
                        transform: `rotate(${Math.round(snapshot.windHeadingDeg)}deg)`,
                    }}
                >
                    ↑
                </span>
            </span>
            {snapshot.photosTaken > 0 && (
                <span className={cn(chip, 'text-cyan-300')}>
                    CAM {snapshot.photosTaken}
                </span>
            )}
            <span className={cn(chip, 'ml-auto flex items-center gap-1.5')}>
                BAT {Math.round(snapshot.batteryPct)}%
                <span className="inline-block h-1.5 w-10 overflow-hidden rounded-full bg-slate-700">
                    <span
                        className={cn(
                            'block h-full',
                            batteryBarClass(snapshot.batteryPct),
                        )}
                        style={{ width: `${Math.round(snapshot.batteryPct)}%` }}
                    />
                </span>
            </span>
        </div>
    );
}
