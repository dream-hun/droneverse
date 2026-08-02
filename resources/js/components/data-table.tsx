import { ArrowDown, ArrowUp, ChevronsUpDown, RefreshCw } from 'lucide-react';
import { useId, useMemo, useState } from 'react';
import type { ReactNode } from 'react';
import { RowActions } from '@/components/row-actions';
import { Button } from '@/components/ui/button';
import {
    EmptyState,
    EmptyStateDescription,
    EmptyStateTitle,
} from '@/components/ui/empty-state';
import {
    ErrorState,
    ErrorStateActions,
    ErrorStateDescription,
    ErrorStateIcon,
    ErrorStateTitle,
} from '@/components/ui/error-state';
import { Skeleton } from '@/components/ui/skeleton';
import {
    Table,
    TableBody,
    TableCaption,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { isRefreshing, resolveDataState } from '@/lib/data-state';
import {
    ALIGN_CLASS,
    ALIGN_JUSTIFY_CLASS,
    HIDE_BELOW_CLASS,
    ariaSortFor,
    cycleSort,
    sortHint,
    sortRows,
} from '@/lib/data-table';
import type { ColumnDef, SortState } from '@/lib/data-table';
import type { RowAction } from '@/lib/row-actions';
import { cn } from '@/lib/utils';

type DataTableProps<TRow> = {
    columns: ColumnDef<TRow>[];
    /** `undefined` means not loaded — the table shows skeleton rows. */
    rows: TRow[] | undefined;
    /** Must be stable across renders; the array index is not a row identity. */
    rowKey: (row: TRow, index: number) => string | number;
    /**
     * Describes the table in a sentence. Doubles as the accessible name of the
     * scroll container, so a keyboard user who tabs into it hears what they
     * are scrolling.
     */
    caption: string;
    captionVisible?: boolean;
    error?: unknown;
    /** A refresh over rows already on screen; does not replace them. */
    isLoading?: boolean;
    empty?: ReactNode;
    onRetry?: () => void;
    /** Controlled sort. Pair with `onSortChange`. */
    sort?: SortState;
    defaultSort?: SortState;
    onSortChange?: (sort: SortState) => void;
    /**
     * Rows are already ordered by the server: headers still cycle and report
     * `aria-sort`, but this component will not reorder anything. Use it for
     * anything paginated, where sorting the current page alone would be a lie.
     */
    manualSorting?: boolean;
    /** Adds a trailing grouped-menu column. */
    actions?: (row: TRow) => RowAction[];
    /** Names each row's menu, e.g. `` (row) => `Actions for ${row.title}` ``. */
    actionsLabel?: (row: TRow) => string;
    rowClassName?: (row: TRow, index: number) => string | undefined;
    /**
     * A row kept visible below the list even when it falls outside it — the
     * "your rank" case. Pass it only when the row is genuinely absent above.
     */
    pinned?: { row: TRow; separator?: ReactNode };
    stickyHeader?: boolean;
    skeletonRows?: number;
    className?: string;
};

function SortIcon({ state }: { state: 'ascending' | 'descending' | 'none' }) {
    if (state === 'ascending') {
        return <ArrowUp aria-hidden="true" className="size-3.5" />;
    }

    if (state === 'descending') {
        return <ArrowDown aria-hidden="true" className="size-3.5" />;
    }

    return (
        <ChevronsUpDown
            aria-hidden="true"
            className="size-3.5 opacity-0 transition-opacity group-hover/sort:opacity-60 group-focus-visible/sort:opacity-60"
        />
    );
}

/**
 * A typed table with the four states, sorting and a row-actions menu built in.
 *
 * Columns are plain objects rather than children, which is what lets the
 * loading, empty and error rows span the right number of cells and lets the
 * same definition drive both the header and the body.
 */
export function DataTable<TRow>({
    columns,
    rows,
    rowKey,
    caption,
    captionVisible = false,
    error,
    isLoading,
    empty,
    onRetry,
    sort: controlledSort,
    defaultSort = null,
    onSortChange,
    manualSorting = false,
    actions,
    actionsLabel,
    rowClassName,
    pinned,
    stickyHeader = false,
    skeletonRows = 5,
    className,
}: DataTableProps<TRow>) {
    const captionId = useId();
    const [internalSort, setInternalSort] = useState<SortState>(defaultSort);

    const sort = controlledSort !== undefined ? controlledSort : internalSort;
    const state = resolveDataState({ data: rows, error, isLoading });
    const refreshing = isRefreshing({ data: rows, isLoading });
    const columnCount = columns.length + (actions ? 1 : 0);

    const sorted = useMemo(() => {
        if (!rows || manualSorting || !sort) {
            return rows;
        }

        return sortRows(
            rows,
            columns.find((column) => column.id === sort.columnId),
            sort.direction,
        );
    }, [rows, columns, sort, manualSorting]);

    function handleSort(columnId: string) {
        const next = cycleSort(sort, columnId);

        if (controlledSort === undefined) {
            setInternalSort(next);
        }

        onSortChange?.(next);
    }

    function renderCells(row: TRow, index: number) {
        return (
            <>
                {columns.map((column) => (
                    <TableCell
                        key={column.id}
                        className={cn(
                            ALIGN_CLASS[column.align ?? 'start'],
                            column.hideBelow &&
                                HIDE_BELOW_CLASS[column.hideBelow],
                            column.className,
                        )}
                    >
                        {column.cell(row, index)}
                    </TableCell>
                ))}
                {actions && (
                    <TableCell className="w-10 text-right">
                        <RowActions
                            actions={actions(row)}
                            label={actionsLabel?.(row) ?? 'Row actions'}
                        />
                    </TableCell>
                )}
            </>
        );
    }

    return (
        // Scroll containers need a role, a name and a tab stop, or the columns
        // past the right edge are unreachable without a mouse (WCAG 2.1.1).
        <div
            role="region"
            aria-labelledby={captionId}
            tabIndex={0}
            className={cn(
                'overflow-x-auto rounded-xl border bg-card focus-visible:ring-3 focus-visible:ring-ring/50 focus-visible:outline-none',
                className,
            )}
        >
            <span aria-live="polite" className="sr-only">
                {state === 'loading' ? 'Loading results' : ''}
            </span>

            <Table
                aria-busy={state === 'loading' || refreshing}
                className={cn(refreshing && 'opacity-60 transition-opacity')}
            >
                <TableCaption
                    id={captionId}
                    className={captionVisible ? undefined : 'sr-only'}
                >
                    {caption}
                </TableCaption>

                <TableHeader>
                    <TableRow>
                        {columns.map((column) => {
                            const align = column.align ?? 'start';
                            const sortState = ariaSortFor(column.id, sort);

                            return (
                                <TableHead
                                    key={column.id}
                                    aria-sort={
                                        column.sortValue ? sortState : undefined
                                    }
                                    className={cn(
                                        ALIGN_CLASS[align],
                                        column.hideBelow &&
                                            HIDE_BELOW_CLASS[column.hideBelow],
                                        column.width,
                                        stickyHeader &&
                                            'sticky top-0 z-10 bg-card',
                                        column.headerClassName,
                                    )}
                                >
                                    {column.sortValue ? (
                                        <button
                                            type="button"
                                            onClick={() =>
                                                handleSort(column.id)
                                            }
                                            className={cn(
                                                'group/sort -mx-1 flex w-[calc(100%+0.5rem)] items-center gap-1 rounded px-1 py-0.5 hover:text-foreground focus-visible:ring-3 focus-visible:ring-ring/50 focus-visible:outline-none',
                                                ALIGN_JUSTIFY_CLASS[align],
                                            )}
                                        >
                                            {column.header}
                                            <SortIcon state={sortState} />
                                            <span className="sr-only">
                                                {sortHint(column.id, sort)}
                                            </span>
                                        </button>
                                    ) : (
                                        <>
                                            {column.header}
                                            {column.srHeader && (
                                                <span className="sr-only">
                                                    {column.srHeader}
                                                </span>
                                            )}
                                        </>
                                    )}
                                </TableHead>
                            );
                        })}
                        {actions && (
                            <TableHead className="w-10">
                                <span className="sr-only">Actions</span>
                            </TableHead>
                        )}
                    </TableRow>
                </TableHeader>

                <TableBody>
                    {state === 'loading' &&
                        Array.from({ length: skeletonRows }, (_, rowIndex) => (
                            <TableRow key={`skeleton-${rowIndex}`}>
                                {columns.map((column) => (
                                    <TableCell
                                        key={column.id}
                                        className={cn(
                                            column.hideBelow &&
                                                HIDE_BELOW_CLASS[
                                                    column.hideBelow
                                                ],
                                        )}
                                    >
                                        <Skeleton className="h-4 w-full" />
                                    </TableCell>
                                ))}
                                {actions && (
                                    <TableCell>
                                        <Skeleton className="size-4" />
                                    </TableCell>
                                )}
                            </TableRow>
                        ))}

                    {state === 'error' && (
                        <TableRow>
                            <TableCell colSpan={columnCount} className="p-0">
                                <ErrorState className="rounded-none border-0">
                                    <ErrorStateIcon>
                                        <RefreshCw />
                                    </ErrorStateIcon>
                                    <ErrorStateTitle>
                                        That did not load
                                    </ErrorStateTitle>
                                    <ErrorStateDescription>
                                        Something went wrong on our side. Trying
                                        again usually sorts it.
                                    </ErrorStateDescription>
                                    {onRetry && (
                                        <ErrorStateActions>
                                            <Button
                                                variant="outline"
                                                onClick={onRetry}
                                            >
                                                Try again
                                            </Button>
                                        </ErrorStateActions>
                                    )}
                                </ErrorState>
                            </TableCell>
                        </TableRow>
                    )}

                    {state === 'empty' && (
                        <TableRow>
                            <TableCell colSpan={columnCount} className="p-0">
                                {empty ?? (
                                    <EmptyState className="rounded-none border-0">
                                        <EmptyStateTitle>
                                            Nothing here yet
                                        </EmptyStateTitle>
                                        <EmptyStateDescription>
                                            Rows appear here once there is
                                            something to show.
                                        </EmptyStateDescription>
                                    </EmptyState>
                                )}
                            </TableCell>
                        </TableRow>
                    )}

                    {state === 'ready' &&
                        sorted?.map((row, index) => (
                            <TableRow
                                key={rowKey(row, index)}
                                className={rowClassName?.(row, index)}
                            >
                                {renderCells(row, index)}
                            </TableRow>
                        ))}

                    {state === 'ready' && pinned && (
                        <>
                            {pinned.separator !== undefined && (
                                <TableRow>
                                    <TableCell
                                        colSpan={columnCount}
                                        className="py-1 text-center text-xs text-muted-foreground"
                                    >
                                        {pinned.separator}
                                    </TableCell>
                                </TableRow>
                            )}
                            <TableRow
                                className={rowClassName?.(
                                    pinned.row,
                                    sorted?.length ?? 0,
                                )}
                            >
                                {renderCells(pinned.row, sorted?.length ?? 0)}
                            </TableRow>
                        </>
                    )}
                </TableBody>
            </Table>
        </div>
    );
}
