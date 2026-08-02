/**
 * What a data-backed surface is currently showing.
 *
 * Four states, resolved from one input, so that every list, grid and table in
 * the app agrees on when to show a skeleton and when to show an empty state.
 * Getting this wrong is the usual source of the "empty state flashes before
 * the data arrives" bug: `[]` and "not loaded yet" are different things, and
 * only one of them means the user has nothing.
 */
export type DataState = 'loading' | 'error' | 'empty' | 'ready';

export type DataStateInput<TData> = {
    /**
     * `undefined` means not loaded yet — that is what Inertia hands you for a
     * deferred prop still in flight. `null` means loaded and absent, which is
     * an empty result, not a pending one.
     */
    data: TData | null | undefined;
    /** Anything truthy is treated as a failure; the value is yours to render. */
    error?: unknown;
    /** A refresh running over data that is already on screen. */
    isLoading?: boolean;
};

/** The shape Laravel's paginator sends through an Inertia prop. */
type Paginated = { data: unknown[] };

function isPaginated(value: object): value is Paginated {
    return Array.isArray((value as Paginated).data);
}

/**
 * Whether loaded data has nothing in it.
 *
 * Only collections can be empty. A loaded object or scalar is content, however
 * small, so it never counts as empty — otherwise a legitimately zero-valued
 * record would render as "nothing here yet".
 */
export function isEmptyData(data: unknown): boolean {
    if (Array.isArray(data)) {
        return data.length === 0;
    }

    if (typeof data === 'object' && data !== null && isPaginated(data)) {
        return data.data.length === 0;
    }

    return false;
}

/**
 * Collapse the inputs into the one state a surface should render.
 *
 * Precedence is deliberate. An error only wins while there is nothing to show;
 * once data has arrived, a failed *refresh* must not blank the screen the user
 * was reading, so the state stays `ready` and the caller surfaces the failure
 * beside the data (see `DataBoundary`'s `staleError` slot). This is the
 * difference between a background poll failing and the page failing.
 */
export function resolveDataState<TData>({
    data,
    error,
}: DataStateInput<TData>): DataState {
    if (data === undefined) {
        return error ? 'error' : 'loading';
    }

    if (data === null) {
        return error ? 'error' : 'empty';
    }

    return isEmptyData(data) ? 'empty' : 'ready';
}

/**
 * Whether a request is in flight over data that is already rendered.
 *
 * Drives `aria-busy` and the dimmed overlay rather than a skeleton: replacing
 * visible rows with placeholders on every poll is worse than leaving them up.
 */
export function isRefreshing<TData>({
    data,
    isLoading,
}: DataStateInput<TData>): boolean {
    return isLoading === true && data !== undefined;
}
