import { useMemo, useState } from 'react';
import { cn } from '@/lib/utils';
import type { CurvePoint } from '@/types/analytics';

type AttemptCurveProps = {
    points: CurvePoint[];
    /** The mission's ceiling, so the y-axis is the mission's own scale. */
    maxScore: number;
    /** Names the chart for screen readers and the table view's caption. */
    missionTitle: string;
    className?: string;
};

/*
 * The drawing surface. Fixed user units scaled by the viewBox, so the layout
 * is computed once here rather than re-measured in the browser.
 */
const VIEW_WIDTH = 720;
const VIEW_HEIGHT = 260;
const PAD_TOP = 16;
const PAD_RIGHT = 60;
const PAD_BOTTOM = 30;
const PAD_LEFT = 38;

const PLOT_LEFT = PAD_LEFT;
const PLOT_RIGHT = VIEW_WIDTH - PAD_RIGHT;
const PLOT_TOP = PAD_TOP;
const PLOT_BOTTOM = VIEW_HEIGHT - PAD_BOTTOM;
const PLOT_WIDTH = PLOT_RIGHT - PLOT_LEFT;
const PLOT_HEIGHT = PLOT_BOTTOM - PLOT_TOP;

/** Gridline positions as a fraction of the mission's max score. */
const GRID_FRACTIONS = [0, 0.25, 0.5, 0.75, 1];

/**
 * Above this many points, per-run dots stop being marks and start being a
 * smear. The line still carries the shape and hovering still finds any run.
 */
const DOT_LIMIT = 24;

type Plotted = CurvePoint & { cx: number; cy: number; bestY: number };

/**
 * How one pilot's scores moved on one mission, run by run.
 *
 * Two series with different jobs, so they are drawn differently rather than
 * merely differently coloured: the run's own score is the accent line with a
 * marker per flight, and the best-so-far is a recessive dashed step behind
 * it. The dash pattern is the point — identity never rests on hue alone, so
 * the chart survives a colourblind reader, a greyscale print and a
 * forced-colours mode, and the `<details>` table underneath survives
 * everything else.
 *
 * There is deliberately no second y-axis. Collisions and elapsed time live in
 * the tooltip and the table instead: they are on a different scale from a
 * score, and putting them on their own axis over the same plot would let any
 * two unrelated series be made to look correlated by choosing the ranges.
 */
export function AttemptCurve({
    points,
    maxScore,
    missionTitle,
    className,
}: AttemptCurveProps) {
    const [hovered, setHovered] = useState<number | null>(null);

    // Guard the axis rather than the callers: a mission with a zero ceiling
    // is a seeding error, and dividing by it would take the whole page down.
    const ceiling = maxScore > 0 ? maxScore : 100;

    const plotted = useMemo<Plotted[]>(() => {
        const lastIndex = points.length - 1;

        return points.map((point, index) => ({
            ...point,
            cx:
                lastIndex === 0
                    ? PLOT_LEFT + PLOT_WIDTH / 2
                    : PLOT_LEFT + (index / lastIndex) * PLOT_WIDTH,
            cy: PLOT_BOTTOM - (point.score / ceiling) * PLOT_HEIGHT,
            bestY: PLOT_BOTTOM - (point.best / ceiling) * PLOT_HEIGHT,
        }));
    }, [points, ceiling]);

    const scorePath = useMemo(
        () => plotted.map((p) => `${p.cx},${p.cy}`).join(' '),
        [plotted],
    );

    /*
     * Best-so-far only ever rises, so it is a step function and is drawn as
     * one. Interpolating between its levels would draw a climb the pilot
     * never made — the best did not creep up between two runs, it jumped on
     * the run that beat it.
     */
    const bestPath = useMemo(() => {
        if (plotted.length === 0) {
            return '';
        }

        return plotted.reduce((path, point, index) => {
            if (index === 0) {
                return `M ${point.cx},${point.bestY}`;
            }

            return `${path} L ${point.cx},${plotted[index - 1].bestY} L ${point.cx},${point.bestY}`;
        }, '');
    }, [plotted]);

    const last = plotted.at(-1);
    const active = hovered === null ? null : (plotted[hovered] ?? null);

    /** Nearest run to the pointer, so the hit target is never the dot itself. */
    function trackPointer(event: React.PointerEvent<SVGSVGElement>) {
        if (plotted.length === 0) {
            return;
        }

        const bounds = event.currentTarget.getBoundingClientRect();

        if (bounds.width === 0) {
            return;
        }

        const x = ((event.clientX - bounds.left) / bounds.width) * VIEW_WIDTH;

        let nearest = 0;

        for (let index = 1; index < plotted.length; index++) {
            if (
                Math.abs(plotted[index].cx - x) <
                Math.abs(plotted[nearest].cx - x)
            ) {
                nearest = index;
            }
        }

        setHovered(nearest);
    }

    if (points.length === 0) {
        return null;
    }

    return (
        <div className={cn('space-y-3', className)}>
            <Legend />

            <div className="relative">
                <svg
                    aria-label={`Score per attempt on ${missionTitle}, ${points.length} runs, best ${last?.best ?? 0} of ${ceiling}`}
                    className="w-full text-chart-3 dark:text-chart-1"
                    onPointerLeave={() => setHovered(null)}
                    onPointerMove={trackPointer}
                    role="img"
                    viewBox={`0 0 ${VIEW_WIDTH} ${VIEW_HEIGHT}`}
                >
                    {/* Grid and axis labels stay recessive — they are the
                        ruler, not the reading. */}
                    <g className="text-muted-foreground/25">
                        {GRID_FRACTIONS.map((fraction) => {
                            const y = PLOT_BOTTOM - fraction * PLOT_HEIGHT;

                            return (
                                <line
                                    key={fraction}
                                    stroke="currentColor"
                                    strokeWidth={1}
                                    x1={PLOT_LEFT}
                                    x2={PLOT_RIGHT}
                                    y1={y}
                                    y2={y}
                                />
                            );
                        })}
                    </g>

                    <g className="fill-muted-foreground text-[11px]">
                        {GRID_FRACTIONS.map((fraction) => (
                            <text
                                key={fraction}
                                dominantBaseline="middle"
                                textAnchor="end"
                                x={PLOT_LEFT - 8}
                                y={PLOT_BOTTOM - fraction * PLOT_HEIGHT}
                            >
                                {Math.round(fraction * ceiling)}
                            </text>
                        ))}

                        <text
                            textAnchor="start"
                            x={PLOT_LEFT}
                            y={VIEW_HEIGHT - 8}
                        >
                            Attempt {plotted[0].attempt}
                        </text>

                        {plotted.length > 1 && (
                            <text
                                textAnchor="end"
                                x={PLOT_RIGHT}
                                y={VIEW_HEIGHT - 8}
                            >
                                {last?.attempt}
                            </text>
                        )}
                    </g>

                    {/* Best so far: recessive, dashed, behind the run line. */}
                    <g className="text-muted-foreground">
                        <path
                            d={bestPath}
                            fill="none"
                            stroke="currentColor"
                            strokeDasharray="5 4"
                            strokeWidth={2}
                        />
                    </g>

                    {plotted.length > 1 && (
                        <polyline
                            fill="none"
                            points={scorePath}
                            stroke="currentColor"
                            strokeLinecap="round"
                            strokeLinejoin="round"
                            strokeWidth={2}
                        />
                    )}

                    {/* A filled marker cleared the mission, a hollow one did
                        not — state the reader can get without the tooltip. */}
                    {plotted.length <= DOT_LIMIT &&
                        plotted.map((point) => (
                            <circle
                                cx={point.cx}
                                cy={point.cy}
                                fill={
                                    point.completed
                                        ? 'currentColor'
                                        : 'var(--card)'
                                }
                                key={point.attempt}
                                r={4}
                                stroke="currentColor"
                                strokeWidth={2}
                            />
                        ))}

                    {active && (
                        <g>
                            <line
                                className="text-muted-foreground/40"
                                stroke="currentColor"
                                strokeWidth={1}
                                x1={active.cx}
                                x2={active.cx}
                                y1={PLOT_TOP}
                                y2={PLOT_BOTTOM}
                            />
                            {/* A ring in the surface colour keeps the marker
                                legible where it lands on the line beneath. */}
                            <circle
                                cx={active.cx}
                                cy={active.cy}
                                fill="currentColor"
                                r={5}
                                stroke="var(--card)"
                                strokeWidth={2}
                            />
                        </g>
                    )}

                    {/* Direct labels, so the two numbers that matter are
                        readable without hovering anything. */}
                    {last && (
                        <g className="text-[11px] font-medium">
                            <text
                                className="fill-foreground"
                                dominantBaseline="middle"
                                x={PLOT_RIGHT + 8}
                                y={last.cy}
                            >
                                {last.score}
                            </text>
                            {last.best !== last.score && (
                                <text
                                    className="fill-muted-foreground"
                                    dominantBaseline="middle"
                                    x={PLOT_RIGHT + 8}
                                    y={last.bestY}
                                >
                                    {last.best}
                                </text>
                            )}
                        </g>
                    )}
                </svg>

                {active && <Tooltip point={active} ceiling={ceiling} />}
            </div>

            <RunTable missionTitle={missionTitle} points={points} />
        </div>
    );
}

function Legend() {
    return (
        <div className="flex flex-wrap items-center gap-4 text-xs text-muted-foreground">
            <span className="inline-flex items-center gap-2">
                <svg
                    aria-hidden="true"
                    className="text-chart-3 dark:text-chart-1"
                    height="8"
                    viewBox="0 0 20 8"
                    width="20"
                >
                    <line
                        stroke="currentColor"
                        strokeWidth={2}
                        x1="0"
                        x2="20"
                        y1="4"
                        y2="4"
                    />
                </svg>
                Score on the run
            </span>
            <span className="inline-flex items-center gap-2">
                <svg
                    aria-hidden="true"
                    height="8"
                    viewBox="0 0 20 8"
                    width="20"
                >
                    <line
                        stroke="currentColor"
                        strokeDasharray="5 4"
                        strokeWidth={2}
                        x1="0"
                        x2="20"
                        y1="4"
                        y2="4"
                    />
                </svg>
                Best so far
            </span>
        </div>
    );
}

/**
 * The run under the pointer, in full.
 *
 * Pinned to the top of the plot rather than following the cursor: a tooltip
 * that chases the pointer across a 50-point line is harder to read than one
 * that stays put, and it cannot fall off the edge of its container.
 */
function Tooltip({ point, ceiling }: { point: Plotted; ceiling: number }) {
    return (
        <div className="pointer-events-none absolute top-0 right-0 rounded-md border bg-popover px-3 py-2 text-xs shadow-md">
            <p className="font-medium">Attempt {point.attempt}</p>
            <dl className="mt-1 grid grid-cols-[auto_auto] gap-x-3 gap-y-0.5 text-muted-foreground">
                <dt>Score</dt>
                <dd className="text-right text-foreground tabular-nums">
                    {point.score} / {ceiling}
                </dd>
                <dt>Objectives</dt>
                <dd className="text-right text-foreground tabular-nums">
                    {point.objectivesHit} / {point.objectivesTotal}
                </dd>
                <dt>Collisions</dt>
                <dd className="text-right text-foreground tabular-nums">
                    {point.collisions}
                </dd>
                <dt>Time</dt>
                <dd className="text-right text-foreground tabular-nums">
                    {point.elapsedSeconds.toFixed(1)}s
                </dd>
                <dt>Result</dt>
                <dd className="text-right text-foreground">
                    {point.completed ? `Cleared · ${point.stars}★` : 'Missed'}
                </dd>
            </dl>
        </div>
    );
}

/**
 * The same runs as a table.
 *
 * Not a fallback — the accessible reading of the chart, and the one a pilot
 * can select and copy. Collapsed because most readers want the shape.
 */
function RunTable({
    points,
    missionTitle,
}: {
    points: CurvePoint[];
    missionTitle: string;
}) {
    return (
        <details className="text-sm">
            <summary className="cursor-pointer text-xs text-muted-foreground hover:text-foreground">
                Show these {points.length} runs as a table
            </summary>
            <div className="mt-2 max-h-64 overflow-auto rounded-md border">
                <table className="w-full text-xs">
                    <caption className="sr-only">
                        Every recorded run on {missionTitle}, oldest first
                    </caption>
                    <thead className="sticky top-0 bg-muted text-muted-foreground">
                        <tr>
                            <th className="px-2 py-1.5 text-left font-medium">
                                Attempt
                            </th>
                            <th className="px-2 py-1.5 text-right font-medium">
                                Score
                            </th>
                            <th className="px-2 py-1.5 text-right font-medium">
                                Best
                            </th>
                            <th className="px-2 py-1.5 text-right font-medium">
                                Objectives
                            </th>
                            <th className="px-2 py-1.5 text-right font-medium">
                                Collisions
                            </th>
                            <th className="px-2 py-1.5 text-right font-medium">
                                Time
                            </th>
                        </tr>
                    </thead>
                    <tbody className="divide-y">
                        {points.map((point) => (
                            <tr key={point.attempt}>
                                <td className="px-2 py-1.5 tabular-nums">
                                    {point.attempt}
                                </td>
                                <td className="px-2 py-1.5 text-right tabular-nums">
                                    {point.score}
                                </td>
                                <td className="px-2 py-1.5 text-right text-muted-foreground tabular-nums">
                                    {point.best}
                                </td>
                                <td className="px-2 py-1.5 text-right tabular-nums">
                                    {point.objectivesHit}/
                                    {point.objectivesTotal}
                                </td>
                                <td className="px-2 py-1.5 text-right tabular-nums">
                                    {point.collisions}
                                </td>
                                <td className="px-2 py-1.5 text-right tabular-nums">
                                    {point.elapsedSeconds.toFixed(1)}s
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>
        </details>
    );
}
