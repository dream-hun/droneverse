import { RefreshCw } from 'lucide-react';
import type { ReactNode } from 'react';
import { Button } from '@/components/ui/button';
import {
    ErrorState,
    ErrorStateActions,
    ErrorStateDescription,
    ErrorStateIcon,
    ErrorStateTitle,
} from '@/components/ui/error-state';
import { isRefreshing, resolveDataState } from '@/lib/data-state';
import { cn } from '@/lib/utils';

type DataBoundaryProps<TData> = {
    /** `undefined` while a deferred prop is still in flight. */
    data: TData | null | undefined;
    error?: unknown;
    isLoading?: boolean;
    /**
     * Shown in place of the content while loading. Make it the same shape and
     * height as the real thing — a skeleton that does not match is a layout
     * shift with extra steps.
     */
    skeleton: ReactNode;
    empty: ReactNode;
    /** Overrides the built-in failure block. */
    errorState?: ReactNode;
    onRetry?: () => void;
    /**
     * Rendered above the data when a *refresh* fails while data is on screen.
     * Without it, that failure is silent — the state stays `ready` on purpose,
     * because blanking a page the user is reading is the worse outcome.
     */
    staleError?: ReactNode;
    children: (data: NonNullable<TData>) => ReactNode;
    className?: string;
};

/**
 * Renders exactly one of loading / error / empty / content for any surface.
 *
 * `DataTable` handles this itself for tables. Use this for everything else —
 * grids, lists, detail panels — so they all make the same call about when a
 * skeleton appears.
 */
export function DataBoundary<TData>({
    data,
    error,
    isLoading,
    skeleton,
    empty,
    errorState,
    onRetry,
    staleError,
    children,
    className,
}: DataBoundaryProps<TData>) {
    const state = resolveDataState({ data, error, isLoading });
    const refreshing = isRefreshing({ data, isLoading });

    return (
        <div
            className={cn('relative', className)}
            aria-busy={state === 'loading' || refreshing}
        >
            <span aria-live="polite" className="sr-only">
                {state === 'loading' ? 'Loading' : ''}
            </span>

            {state === 'loading' && skeleton}

            {state === 'error' &&
                (errorState ?? (
                    <ErrorState>
                        <ErrorStateIcon>
                            <RefreshCw />
                        </ErrorStateIcon>
                        <ErrorStateTitle>That did not load</ErrorStateTitle>
                        <ErrorStateDescription>
                            Something went wrong on our side. Trying again
                            usually sorts it.
                        </ErrorStateDescription>
                        {onRetry && (
                            <ErrorStateActions>
                                <Button variant="outline" onClick={onRetry}>
                                    Try again
                                </Button>
                            </ErrorStateActions>
                        )}
                    </ErrorState>
                ))}

            {state === 'empty' && empty}

            {state === 'ready' && (
                <>
                    {error != null && staleError}
                    <div
                        className={cn(
                            refreshing && 'opacity-60 transition-opacity',
                        )}
                    >
                        {children(data as NonNullable<TData>)}
                    </div>
                </>
            )}
        </div>
    );
}
