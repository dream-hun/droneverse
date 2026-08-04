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

function modeToneClass(mode: string): string {
    if (mode === 'STANDBY') {
        return 'text-slate-300';
    }

    if (mode === 'LANDED') {
        return 'text-emerald-300';
    }

    if (mode === 'SPOOL UP' || mode === 'LANDING') {
        return 'text-amber-300';
    }

    return 'text-cyan-300';
}

function batteryToneClass(batteryPct: number): string {
    if (batteryPct > 50) {
        return 'text-emerald-300';
    }

    if (batteryPct > 25) {
        return 'text-amber-300';
    }

    return 'text-red-400';
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
 * The HUD is drawn on top of a lit 3D scene, not on the page background, so
 * it is styled against the render rather than against the app theme: fixed
 * dark glass in both colour schemes, because a light panel over a daylight
 * sky is unreadable and a light panel over shadowed geometry is a glare.
 */
const PANEL =
    'pointer-events-none rounded-md border border-white/10 bg-[#0b1016]/80 backdrop-blur-sm';

const LABEL = 'text-[9px] font-medium tracking-[0.14em] text-slate-400';

/** One `LABEL  value unit` line of the telemetry rail. */
function Readout({
    label,
    value,
    unit,
    tone,
}: {
    label: string;
    value: string;
    unit?: string;
    tone?: string;
}) {
    return (
        <div className="flex items-baseline justify-between gap-2">
            <span className={LABEL}>{label}</span>
            <span className={cn('tabular-nums', tone ?? 'text-slate-100')}>
                {value}
                {unit && <span className="ml-0.5 text-slate-500">{unit}</span>}
            </span>
        </div>
    );
}

/**
 * Cockpit overlay for the 3D viewport: a status chip on the nose of the
 * frame, a telemetry rail down the right edge, and a battery strip along the
 * bottom. Polls the mutable flight state at 10 Hz — plenty for numeric
 * readouts without re-rendering the page at frame rate.
 *
 * The poll is a poll, not a change notification: the flight state is
 * mutated in place by the physics loop and says nothing when it moves. So
 * the snapshot it produces is kept only when it would actually change what
 * is on screen. Handed to `setState` unconditionally, a fresh object every
 * 100 ms was ten renders a second for the whole overlay — for the entire
 * time the page was open, including the STANDBY drone sitting on its pad
 * before anyone has written a line of code.
 *
 * The rail is the first thing to go when the viewport is narrow: on a phone
 * the render is small enough that covering a right-hand column of it costs
 * more than the readouts are worth, and everything urgent (mode, battery)
 * lives in the two strips that always show.
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

    const battery = Math.round(snapshot.batteryPct);

    return (
        <div className="pointer-events-none absolute inset-0 z-10 font-mono text-[11px]">
            <div
                className={cn(
                    PANEL,
                    'absolute top-2 left-2 flex items-center gap-2 px-2.5 py-1.5',
                )}
            >
                <span className={LABEL}>STATUS</span>
                <span
                    className={cn(
                        'font-semibold tracking-wide',
                        modeToneClass(snapshot.mode),
                    )}
                >
                    {snapshot.mode}
                </span>
                {snapshot.photosTaken > 0 && (
                    <span className="text-cyan-300">
                        <span className={cn(LABEL, 'mr-1')}>CAM</span>
                        {snapshot.photosTaken}
                    </span>
                )}
            </div>

            <div
                className={cn(
                    PANEL,
                    'absolute top-12 right-2 hidden w-32 space-y-1 px-2.5 py-2 sm:block',
                )}
            >
                <p
                    className={cn(
                        LABEL,
                        'border-b border-white/10 pb-1.5 text-slate-300',
                    )}
                >
                    TELEMETRY
                </p>
                <Readout
                    label="ALT"
                    value={snapshot.altitude.toFixed(1)}
                    unit="m"
                />
                <Readout
                    label="SPD"
                    value={snapshot.groundSpeed.toFixed(1)}
                    unit="m/s"
                />
                <Readout
                    label="V/S"
                    value={`${snapshot.verticalSpeed >= 0 ? '+' : ''}${snapshot.verticalSpeed.toFixed(1)}`}
                    unit="m/s"
                />
                <Readout
                    label="HDG"
                    value={`${String(Math.round(snapshot.headingDeg)).padStart(3, '0')}°`}
                />
                <Readout
                    label="WIND"
                    value={snapshot.windSpeed.toFixed(1)}
                    unit="m/s"
                />
                <Readout
                    label="BAT"
                    value={`${battery}%`}
                    tone={batteryToneClass(battery)}
                />
            </div>

            <div
                className={cn(
                    PANEL,
                    'absolute right-2 bottom-2 left-2 flex items-center gap-3 px-2.5 py-1.5',
                )}
            >
                <span className="text-slate-100 tabular-nums">
                    <span className={cn(LABEL, 'mr-1')}>ALT</span>
                    {snapshot.altitude.toFixed(1)}
                    <span className="text-slate-500">m</span>
                </span>
                <span className="text-slate-100 tabular-nums">
                    <span className={cn(LABEL, 'mr-1')}>SPD</span>
                    {snapshot.groundSpeed.toFixed(1)}
                    <span className="text-slate-500">m/s</span>
                </span>
                <span className="text-slate-100 tabular-nums">
                    <span className={cn(LABEL, 'mr-1')}>HDG</span>
                    {String(Math.round(snapshot.headingDeg)).padStart(3, '0')}°
                </span>
                <span className="hidden items-center text-slate-100 tabular-nums md:inline-flex">
                    <span className={cn(LABEL, 'mr-1')}>WIND</span>
                    {snapshot.windSpeed.toFixed(1)}
                    <span
                        className="ml-1 inline-block text-slate-400"
                        style={{
                            transform: `rotate(${Math.round(snapshot.windHeadingDeg)}deg)`,
                        }}
                    >
                        ↑
                    </span>
                </span>

                <span className="ml-auto flex items-center gap-2">
                    <span className={LABEL}>BAT</span>
                    <span className="h-1 w-14 overflow-hidden rounded-full bg-white/10">
                        <span
                            className={cn(
                                'block h-full transition-[width] duration-300',
                                batteryBarClass(battery),
                            )}
                            style={{ width: `${battery}%` }}
                        />
                    </span>
                    <span
                        className={cn(
                            'tabular-nums',
                            batteryToneClass(battery),
                        )}
                    >
                        {battery}%
                    </span>
                </span>
            </div>
        </div>
    );
}
