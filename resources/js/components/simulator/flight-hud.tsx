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
 */
export function FlightHud({ flightState }: { flightState: FlightVisualState }) {
    const [snapshot, setSnapshot] = useState<HudSnapshot>(() =>
        takeSnapshot(flightState),
    );

    useEffect(() => {
        const id = window.setInterval(
            () => setSnapshot(takeSnapshot(flightState)),
            100,
        );

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
