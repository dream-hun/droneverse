import { useSyncExternalStore } from 'react';

const MOBILE_BREAKPOINT = 768;

/**
 * One MediaQueryList for the whole app, created at module scope.
 *
 * Creating it per subscription minted a fresh matcher for every consumer of
 * the same query, and — the part that could actually be seen — left the
 * snapshot and the change event reading different sources: `innerWidth`
 * decided what was rendered while the media query decided when to re-render.
 * Reading `matches` makes the value and the event the same authority, so the
 * two cannot disagree at the boundary.
 *
 * Guarded for SSR, where there is no `window` to query and the server
 * snapshot answers instead.
 */
const query =
    typeof window === 'undefined'
        ? undefined
        : window.matchMedia(`(max-width: ${MOBILE_BREAKPOINT - 1}px)`);

function subscribe(onChange: () => void): () => void {
    if (!query) {
        return () => {};
    }

    query.addEventListener('change', onChange);

    return () => query.removeEventListener('change', onChange);
}

function getSnapshot(): boolean {
    return query?.matches ?? false;
}

/** Rendered on the server, before any viewport is known. */
function getServerSnapshot(): boolean {
    return false;
}

export function useIsMobile(): boolean {
    return useSyncExternalStore(subscribe, getSnapshot, getServerSnapshot);
}
