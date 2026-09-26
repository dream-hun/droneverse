import { useState } from 'react';
import { cn } from '@/lib/utils';

export type Column = {
    /** Stable key and the table view's row label, e.g. `2026-09-25`. */
    key: string;
    /** Short axis label, e.g. `25 Sep`. */
    label: string;
    value: number;
    /** What the tooltip and table show; defaults to the grouped number. */
    display?: string;
};

type ColumnChartProps = {
    columns: Column[];
    /** Names the chart for screen readers and captions the table view. */
    title: string;
    className?: string;
};

const VIEW_WIDTH = 720;
const VIEW_HEIGHT = 200;
const PAD_TOP = 12;
const PAD_BOTTOM = 24;
const PAD_LEFT = 44;
const PAD_RIGHT = 8;
const PLOT_WIDTH = VIEW_WIDTH - PAD_LEFT - PAD_RIGHT;
const PLOT_HEIGHT = VIEW_HEIGHT - PAD_TOP - PAD_BOTTOM;
const BASELINE = VIEW_HEIGHT - PAD_BOTTOM;
const MAX_COLUMN_WIDTH = 24;
const GAP = 2;
const RADIUS = 4;

/** A clean ceiling for the y-axis: 1, 2 or 5 times a power of ten. */
function niceCeiling(max: number): number {
    if (max <= 0) {
        return 1;
    }

    const power = 10 ** Math.floor(Math.log10(max));

    return ([1, 2, 5, 10].find((step) => step * power >= max) ?? 10) * power;
}

/**
 * A column path with a rounded data-end and a square baseline.
 */
function columnPath(x: number, width: number, height: number): string {
    const r = Math.min(RADIUS, width / 2, height);
    const top = BASELINE - height;

    return [
        `M${x},${BASELINE}`,
        `V${top + r}`,
        `Q${x},${top} ${x + r},${top}`,
        `H${x + width - r}`,
        `Q${x + width},${top} ${x + width},${top + r}`,
        `V${BASELINE}`,
        'Z',
    ].join(' ');
}

/**
 * One series over time, as columns from a shared baseline.
 *
 * Single-series by design — the title names what is plotted, so there is no
 * legend box — and never dual-axis: two measures get two charts. Hover a
 * column (its hit target is the whole slot, not just the mark) for its value;
 * the same numbers are always one click away as a table.
 */
export function ColumnChart({ columns, title, className }: ColumnChartProps) {
    const [hovered, setHovered] = useState<number | null>(null);

    const ceiling = niceCeiling(Math.max(0, ...columns.map((c) => c.value)));
    const slot = columns.length > 0 ? PLOT_WIDTH / columns.length : PLOT_WIDTH;
    const width = Math.max(2, Math.min(MAX_COLUMN_WIDTH, slot - GAP));
    const labelEvery = Math.ceil(columns.length / 7);
    const active = hovered === null ? null : columns[hovered];

    return (
        <figure className={cn('space-y-2', className)}>
            <div className="relative">
                <svg
                    role="img"
                    aria-label={title}
                    viewBox={`0 0 ${VIEW_WIDTH} ${VIEW_HEIGHT}`}
                    className="w-full text-chart-3 dark:text-chart-1"
                    onPointerLeave={() => setHovered(null)}
                >
                    <g className="text-muted-foreground/25">
                        {[0, 0.5, 1].map((fraction) => (
                            <line
                                key={fraction}
                                x1={PAD_LEFT}
                                x2={VIEW_WIDTH - PAD_RIGHT}
                                y1={BASELINE - fraction * PLOT_HEIGHT}
                                y2={BASELINE - fraction * PLOT_HEIGHT}
                                stroke="currentColor"
                                strokeWidth={1}
                            />
                        ))}
                    </g>

                    <g className="fill-muted-foreground text-[11px]">
                        {[0, 0.5, 1].map((fraction) => (
                            <text
                                key={fraction}
                                x={PAD_LEFT - 8}
                                y={BASELINE - fraction * PLOT_HEIGHT}
                                textAnchor="end"
                                dominantBaseline="middle"
                            >
                                {Math.round(
                                    fraction * ceiling,
                                ).toLocaleString()}
                            </text>
                        ))}
                        {columns.map((column, index) =>
                            index % labelEvery === 0 ? (
                                <text
                                    key={column.key}
                                    x={PAD_LEFT + slot * index + slot / 2}
                                    y={VIEW_HEIGHT - 6}
                                    textAnchor="middle"
                                >
                                    {column.label}
                                </text>
                            ) : null,
                        )}
                    </g>

                    {columns.map((column, index) => {
                        const height = (column.value / ceiling) * PLOT_HEIGHT;
                        const x = PAD_LEFT + slot * index + (slot - width) / 2;

                        return (
                            <g key={column.key}>
                                {height > 0 && (
                                    <path
                                        d={columnPath(x, width, height)}
                                        fill="currentColor"
                                        opacity={
                                            hovered === null ||
                                            hovered === index
                                                ? 1
                                                : 0.45
                                        }
                                    />
                                )}
                                <rect
                                    x={PAD_LEFT + slot * index}
                                    y={PAD_TOP}
                                    width={slot}
                                    height={PLOT_HEIGHT}
                                    fill="transparent"
                                    onPointerEnter={() => setHovered(index)}
                                />
                            </g>
                        );
                    })}
                </svg>

                {active && (
                    <div className="pointer-events-none absolute top-0 right-0 rounded-none border bg-popover px-2 py-1 text-xs text-popover-foreground shadow-sm">
                        <span className="text-muted-foreground">
                            {active.label}
                        </span>{' '}
                        <span className="font-medium tabular-nums">
                            {active.display ?? active.value.toLocaleString()}
                        </span>
                    </div>
                )}
            </div>

            <details className="text-xs">
                <summary className="cursor-pointer text-muted-foreground">
                    Show as a table
                </summary>
                <table className="mt-2 w-full">
                    <caption className="sr-only">{title}</caption>
                    <tbody>
                        {columns.map((column) => (
                            <tr
                                key={column.key}
                                className="border-b last:border-0"
                            >
                                <th
                                    scope="row"
                                    className="py-1 text-left font-normal"
                                >
                                    {column.key}
                                </th>
                                <td className="py-1 text-right tabular-nums">
                                    {column.display ??
                                        column.value.toLocaleString()}
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </details>
        </figure>
    );
}
