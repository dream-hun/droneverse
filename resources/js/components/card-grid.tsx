import { Inbox } from 'lucide-react';
import type { ReactNode } from 'react';
import { DataBoundary } from '@/components/data-boundary';
import {
    EmptyState,
    EmptyStateDescription,
    EmptyStateIcon,
    EmptyStateTitle,
} from '@/components/ui/empty-state';
import { gridColumnsClass, skeletonItemCount } from '@/lib/card-grid';
import type { GridColumns } from '@/lib/card-grid';
import { cn } from '@/lib/utils';

type CardGridProps<TItem> = {
    /** `undefined` means not loaded — the grid shows placeholder cards. */
    items: readonly TItem[] | null | undefined;
    /** Must be stable across renders; the array index is not an identity. */
    itemKey: (item: TItem, index: number) => string | number;
    /**
     * Names the collection for assistive tech, e.g. `"Courses"`. Screen
     * readers announce it with the item count when entering the list, which is
     * the one thing a sighted user gets for free from the layout.
     */
    label: string;
    children: (item: TItem, index: number) => ReactNode;
    /**
     * One placeholder card, repeated. Give it the same height as the real
     * card — a skeleton of the wrong size is a layout shift with extra steps.
     */
    skeleton?: ReactNode;
    /** Rounded up to fill whole rows. Ignored when `skeleton` is omitted. */
    skeletonItems?: number;
    empty?: ReactNode;
    error?: unknown;
    /** A refresh over cards already on screen; does not replace them. */
    isLoading?: boolean;
    onRetry?: () => void;
    staleError?: ReactNode;
    /**
     * `stack` is the same component in one full-width column — used where the
     * cards are wide rows rather than tiles. It keeps the states and the list
     * semantics identical instead of forking a second component for it.
     */
    layout?: 'grid' | 'stack';
    columns?: GridColumns;
    className?: string;
    itemClassName?: string;
};

function DefaultEmpty({ label }: { label: string }) {
    return (
        <EmptyState>
            <EmptyStateIcon>
                <Inbox />
            </EmptyStateIcon>
            <EmptyStateTitle>Nothing here yet</EmptyStateTitle>
            <EmptyStateDescription>
                {label} will show up here once there are some.
            </EmptyStateDescription>
        </EmptyState>
    );
}

/**
 * A collection of cards with the four states and list semantics built in.
 *
 * The card-shaped counterpart to `DataTable`: same contract, same handling of
 * loading vs empty, but the caller renders each item instead of declaring
 * columns. Reach for it wherever a page would otherwise write
 * `items.length > 0 ? <grid> : <empty>` — that shape has no answer for "not
 * loaded yet" and flashes the empty state on the way to the data.
 */
export function CardGrid<TItem>({
    items,
    itemKey,
    label,
    children,
    skeleton,
    skeletonItems = 6,
    empty,
    error,
    isLoading,
    onRetry,
    staleError,
    layout = 'grid',
    columns = 3,
    className,
    itemClassName,
}: CardGridProps<TItem>) {
    const effectiveColumns: GridColumns = layout === 'stack' ? 1 : columns;

    const listClass =
        layout === 'stack'
            ? 'flex flex-col gap-3'
            : cn('grid gap-4', gridColumnsClass(effectiveColumns));

    const placeholders = skeleton ? (
        <div aria-hidden="true" className={listClass}>
            {Array.from({
                length: skeletonItemCount(effectiveColumns, skeletonItems),
            }).map((_, index) => (
                <div key={index} className={itemClassName}>
                    {skeleton}
                </div>
            ))}
        </div>
    ) : null;

    return (
        <DataBoundary
            data={items}
            error={error}
            isLoading={isLoading}
            onRetry={onRetry}
            staleError={staleError}
            skeleton={placeholders}
            empty={empty ?? <DefaultEmpty label={label} />}
            className={className}
        >
            {(loaded) => (
                <ul
                    // Tailwind's reset removes the bullets, and Safari drops
                    // list semantics along with them. The explicit roles put
                    // the item count back for VoiceOver.
                    role="list"
                    aria-label={label}
                    className={listClass}
                >
                    {loaded.map((item, index) => (
                        <li
                            role="listitem"
                            key={itemKey(item, index)}
                            className={itemClassName}
                        >
                            {children(item, index)}
                        </li>
                    ))}
                </ul>
            )}
        </DataBoundary>
    );
}
