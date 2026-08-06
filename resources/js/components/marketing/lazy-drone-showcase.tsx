import { lazy, Suspense } from 'react';
import type { DroneModelSummary } from '@/types/drone';

/**
 * Defers the landing page's 3D drone until after the page itself is up.
 *
 * The showcase pulls in three.js, react-three-fiber and drei — the better
 * part of a megabyte before compression. Imported directly it lands in the
 * critical path of the one page every visitor sees first, and it is
 * decoration: the headline, the copy, and the call to action do not depend
 * on it. Behind a dynamic import the page paints on its own, and the drone
 * fades in when it arrives.
 */
const DroneShowcase = lazy(() =>
    import('@/components/marketing/drone-showcase').then((module) => ({
        default: module.DroneShowcase,
    })),
);

/** Holds the drone's space so the surrounding layout never shifts. */
function ShowcasePlaceholder() {
    return (
        <div
            aria-hidden
            className="h-full w-full animate-pulse rounded-xl bg-muted/40"
        />
    );
}

export function LazyDroneShowcase({ drone }: { drone: DroneModelSummary }) {
    return (
        <Suspense fallback={<ShowcasePlaceholder />}>
            <DroneShowcase drone={drone} />
        </Suspense>
    );
}
