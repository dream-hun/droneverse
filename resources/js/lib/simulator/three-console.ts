import { setConsoleFunction } from 'three';

/**
 * Deprecations three.js reports about calls this application never makes.
 *
 * three prefixes its own messages with `THREE.`, so these are matched whole
 * rather than by fragment: a wording change upstream should reintroduce the
 * warning and fail the test that pins it, not silently widen the filter.
 */
const UPSTREAM_DEPRECATIONS = new Set([
    // react-three-fiber builds the root store's `state.clock` with
    // `new THREE.Clock()`, and 9.7.0 — the current release — still does.
    // three deprecated Clock in r183 and warns from its constructor, so the
    // line arrives once per canvas mount. `clock` is part of fiber's public
    // `RootState`, which leaves no prop, option or ordering trick that keeps
    // the viewport from constructing one; the only thing under this
    // application's control is whether the pilot's console carries a notice
    // about a class it does not use and cannot stop fiber from using.
    'THREE.Clock: This module has been deprecated. Please use THREE.Timer instead.',
]);

/**
 * Drops the warnings above from the console, and nothing else.
 *
 * three routes every message it raises through this hook for exactly this
 * purpose, so the handler stands in for `console.log`/`warn`/`error` on
 * three's own output alone — the rest of the page logs as it always did.
 * Everything three raises that is not listed above is forwarded, which is the
 * part that matters: the viewport is debugged through three's warnings, and a
 * filter that swallowed a failed shader compile or a misconfigured render
 * target would cost far more than the noise it saves.
 *
 * Forwarded, but not quite verbatim. three unwraps a `StackTrace` parameter
 * into a located `Error` only on its *un-hooked* path, so a message carrying
 * one arrives here as `(message, opaqueObject)` and logs that way. Only the
 * TSL/node-material paths attach one, and this application renders through
 * WebGL and never touches them — if that changes, this is the line that has
 * to grow the same unwrapping.
 *
 * Call it before the first `<Canvas>` renders — the clock is constructed
 * while fiber builds its store, which is the first thing a canvas does.
 *
 * Delete the entry, and this module with it, once fiber moves to THREE.Timer.
 */
export function filterUpstreamThreeWarnings(): void {
    setConsoleFunction((type, message, ...params) => {
        if (UPSTREAM_DEPRECATIONS.has(message)) {
            return;
        }

        console[type](message, ...params);
    });
}
