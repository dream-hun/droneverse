import type { ReactNode } from 'react';

/**
 * Column definitions and the pure logic behind `DataTable`.
 *
 * Kept apart from the component so the parts that can actually be wrong —
 * comparison, sort cycling, null placement — are testable without a DOM.
 */

export type SortDirection = 'asc' | 'desc';

/** `null` is the unsorted third position of the header cycle. */
export type SortState = { columnId: string; direction: SortDirection } | null;

export type ColumnAlign = 'start' | 'center' | 'end';

export type Breakpoint = 'sm' | 'md' | 'lg' | 'xl';

export type SortableValue = string | number | boolean | Date | null | undefined;

export type ColumnDef<TRow> = {
    /** Stable identity: React key, sort key, and URL sort parameter. */
    id: string;
    header: ReactNode;
    cell: (row: TRow, index: number) => ReactNode;
    /**
     * Extracts the comparable value. Supplying it is what makes a column
     * sortable — there is no separate `sortable` flag to fall out of sync.
     */
    sortValue?: (row: TRow) => SortableValue;
    align?: ColumnAlign;
    /** Drop the column below this breakpoint to keep narrow screens readable. */
    hideBelow?: Breakpoint;
    /** Header text for assistive tech when `header` is an icon or empty. */
    srHeader?: string;
    /** Fixed width, e.g. `'w-10'`. */
    width?: string;
    className?: string;
    headerClassName?: string;
};

/**
 * Tailwind scans source for complete class names, so these have to be written
 * out rather than built from the breakpoint at runtime.
 */
export const HIDE_BELOW_CLASS: Record<Breakpoint, string> = {
    sm: 'hidden sm:table-cell',
    md: 'hidden md:table-cell',
    lg: 'hidden lg:table-cell',
    xl: 'hidden xl:table-cell',
};

export const ALIGN_CLASS: Record<ColumnAlign, string> = {
    start: 'text-left',
    center: 'text-center',
    end: 'text-right',
};

/** For the flex row inside a sortable header, which `text-*` cannot move. */
export const ALIGN_JUSTIFY_CLASS: Record<ColumnAlign, string> = {
    start: 'justify-start',
    center: 'justify-center',
    end: 'justify-end',
};

function isMissing(value: SortableValue): boolean {
    return (
        value === null ||
        value === undefined ||
        (typeof value === 'number' && Number.isNaN(value))
    );
}

/**
 * Order two cell values of the same kind.
 *
 * Strings go through `localeCompare` with `numeric` on, so `Mission 2` sorts
 * before `Mission 10` instead of after it, and accented names land where a
 * reader expects. Missing values are not ordered here — `sortRows` pins them.
 */
export function compareValues(a: SortableValue, b: SortableValue): number {
    if (isMissing(a) || isMissing(b)) {
        return isMissing(a) && isMissing(b) ? 0 : isMissing(a) ? 1 : -1;
    }

    if (typeof a === 'number' && typeof b === 'number') {
        return a - b;
    }

    if (a instanceof Date && b instanceof Date) {
        return a.getTime() - b.getTime();
    }

    if (typeof a === 'boolean' && typeof b === 'boolean') {
        return Number(a) - Number(b);
    }

    return String(a).localeCompare(String(b), undefined, {
        numeric: true,
        sensitivity: 'base',
    });
}

/**
 * Sort a copy of `rows`.
 *
 * Two properties worth keeping: rows with no value sink to the bottom in
 * *both* directions — flipping to descending should not float a column of
 * blanks to the top — and equal rows keep their incoming order, so toggling a
 * sort back and forth never reshuffles ties.
 *
 * Never sorts in place: Inertia page props are shared, and mutating them
 * corrupts the cached history entry.
 */
export function sortRows<TRow>(
    rows: readonly TRow[],
    column: ColumnDef<TRow> | undefined,
    direction: SortDirection,
): TRow[] {
    if (!column?.sortValue) {
        return [...rows];
    }

    const read = column.sortValue;

    return rows
        .map((row, index) => ({ row, index, value: read(row) }))
        .sort((a, b) => {
            const aMissing = isMissing(a.value);
            const bMissing = isMissing(b.value);

            if (aMissing || bMissing) {
                return aMissing && bMissing
                    ? a.index - b.index
                    : aMissing
                      ? 1
                      : -1;
            }

            const result = compareValues(a.value, b.value);

            if (result === 0) {
                return a.index - b.index;
            }

            return direction === 'asc' ? result : -result;
        })
        .map((entry) => entry.row);
}

/**
 * The next sort after a header press: ascending, descending, then back to the
 * table's natural order. That third position matters — without it there is no
 * way back to the order the server chose, which is usually the meaningful one.
 */
export function cycleSort(current: SortState, columnId: string): SortState {
    if (current?.columnId !== columnId) {
        return { columnId, direction: 'asc' };
    }

    if (current.direction === 'asc') {
        return { columnId, direction: 'desc' };
    }

    return null;
}

/** The `aria-sort` value for a header cell. */
export function ariaSortFor(
    columnId: string,
    sort: SortState,
): 'ascending' | 'descending' | 'none' {
    if (sort?.columnId !== columnId) {
        return 'none';
    }

    return sort.direction === 'asc' ? 'ascending' : 'descending';
}

/** Announced by the header button so the control is not just an icon. */
export function sortHint(columnId: string, sort: SortState): string {
    const next = cycleSort(sort, columnId);

    if (next === null) {
        return 'Remove sorting';
    }

    return next.direction === 'asc' ? 'Sort ascending' : 'Sort descending';
}
