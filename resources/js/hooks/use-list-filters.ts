import { router } from '@inertiajs/react';

type FilterValue = string | null | undefined;

/**
 * Drive a server-filtered list from the query string.
 *
 * Every filter change is a GET to the same page with the new query, so a
 * filtered list is a URL that can be bookmarked, shared and reloaded. The
 * page number is dropped on every change — page four of the old results is
 * not page four of the new ones — and empty values are left out rather than
 * sent as `?q=`, which keeps the URL honest about what is filtered.
 */
export function useListFilters<TFilters extends Record<string, FilterValue>>(
    url: string,
    filters: TFilters,
) {
    function apply(changes: Partial<Record<keyof TFilters, FilterValue>>) {
        const query: Record<string, string> = {};

        for (const [key, value] of Object.entries({ ...filters, ...changes })) {
            if (typeof value === 'string' && value !== '') {
                query[key] = value;
            }
        }

        router.get(url, query, {
            preserveState: true,
            preserveScroll: true,
            replace: true,
        });
    }

    return { apply };
}
