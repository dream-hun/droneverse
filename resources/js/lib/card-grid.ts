/**
 * Layout maths for collection grids.
 *
 * `DataTable` owns tabular collections. This is the equivalent for the ones
 * that render as cards — course lists, photo grids, challenge stacks — so they
 * agree on how many columns a breakpoint gets and how a skeleton fills them.
 */

/** Columns at the widest breakpoint. Narrow screens always start at one. */
export type GridColumns = 1 | 2 | 3 | 4;

/**
 * Every column count spelled out, because Tailwind extracts class names by
 * scanning source text. An interpolated `grid-cols-${columns}` is never
 * generated and silently falls back to one column in production, where the
 * scan runs against built files rather than a dev-time safelist.
 */
const COLUMN_CLASS: Record<GridColumns, string> = {
    1: 'grid-cols-1',
    2: 'grid-cols-1 sm:grid-cols-2',
    3: 'grid-cols-1 sm:grid-cols-2 lg:grid-cols-3',
    4: 'grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4',
};

/** The responsive `grid-cols-*` classes for a column count. */
export function gridColumnsClass(columns: GridColumns): string {
    return COLUMN_CLASS[columns];
}

/**
 * How many placeholders to draw so the skeleton ends on a full row.
 *
 * A ragged final row of placeholders reads as real content — the user takes
 * the short row for the end of the list, then it reflows when the data lands.
 * Rounding up to a whole multiple of the column count keeps the placeholder a
 * rectangle, which reads as "still loading" instead.
 */
export function skeletonItemCount(
    columns: GridColumns,
    requested: number,
): number {
    if (!Number.isFinite(requested) || requested < 1) {
        return columns;
    }

    const whole = Math.ceil(requested);

    return Math.ceil(whole / columns) * columns;
}
