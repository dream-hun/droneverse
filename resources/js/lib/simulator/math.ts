/**
 * Small numeric helpers shared across the simulator.
 *
 * These had drifted into private copies inside the physics loop, the scene
 * components, and the texture generator. They are the kind of thing that is
 * quicker to rewrite than to import, right up until two copies disagree —
 * so there is exactly one of each here.
 */

export function clamp(value: number, min: number, max: number): number {
    return Math.min(max, Math.max(min, value));
}

/** Fold an angle in degrees into the 0-360 compass range. */
export function normalizeDegrees(degrees: number): number {
    return ((degrees % 360) + 360) % 360;
}

/**
 * Compass heading in degrees for a yaw in radians.
 *
 * The scene's yaw runs counter-clockwise about +Y while a compass runs
 * clockwise from north, hence the sign flip.
 */
export function compassDegrees(radians: number): number {
    return normalizeDegrees((-radians * 180) / Math.PI);
}

/**
 * Deterministic PRNG (mulberry32).
 *
 * Anything generated during render — a building's window pattern, a tree's
 * lean — has to come from one of these rather than `Math.random()`, or it
 * changes on every re-render and the scene visibly flickers.
 */
export function seededRng(seed: number): () => number {
    let state = seed >>> 0;

    return () => {
        state = (state + 0x6d2b79f5) | 0;
        let t = Math.imul(state ^ (state >>> 15), 1 | state);
        t = (t + Math.imul(t ^ (t >>> 7), 61 | t)) ^ t;

        return ((t ^ (t >>> 14)) >>> 0) / 4294967296;
    };
}

/**
 * A stable seed for an object placed at a position in the field.
 *
 * Two objects at the same spot would look alike, but no two objects share a
 * spot, so this is enough to give each its own consistent variation.
 */
export function positionSeed(x: number, z: number, extra = 0): number {
    return (
        Math.abs(
            (Math.round(x * 100) * 73856093) ^
                (Math.round(z * 100) * 19349663) ^
                (Math.round(extra * 100) * 83492791),
        ) >>> 0
    );
}
