import { lazy, Suspense } from 'react';
import type { ComponentProps } from 'react';
import type { SimulatorCanvas as SimulatorCanvasComponent } from '@/components/simulator/simulator-canvas';

/**
 * Defers the 3D viewport until the rest of the mission page is up.
 *
 * The viewport is the heaviest thing the application ships by a wide
 * margin: three.js, drei, react-three-fiber, and the Rapier physics engine,
 * whose WebAssembly binary its bundle carries inline as two megabytes of
 * base64. Imported directly, all of it sat in front of the mission page's
 * first paint — so a pilot arriving to read the briefing and start writing
 * code waited on a physics engine they had not asked for yet.
 *
 * Behind a dynamic import the briefing, the editor, and the console paint
 * on their own and the viewport arrives alongside them. Nothing outside
 * this file changes: the simulator still mounts on load, still owns the
 * session, and the pilot cannot fly before it is ready because the Run
 * button drives the session the viewport registers its controls with.
 */
const SimulatorCanvas = lazy(() =>
    import('@/components/simulator/simulator-canvas').then((module) => ({
        default: module.SimulatorCanvas,
    })),
);

/** Holds the viewport's space, so the page never reflows around it. */
function ViewportPlaceholder() {
    return (
        <div
            aria-hidden
            className="flex h-full w-full items-center justify-center bg-muted/40"
        >
            <div className="flex flex-col items-center gap-3">
                <div className="size-8 animate-spin rounded-full border-2 border-muted-foreground/25 border-t-muted-foreground/70" />
                <p className="text-xs text-muted-foreground">
                    Spinning up the flight deck…
                </p>
            </div>
        </div>
    );
}

export function LazySimulatorCanvas(
    props: ComponentProps<typeof SimulatorCanvasComponent>,
) {
    return (
        <Suspense fallback={<ViewportPlaceholder />}>
            <SimulatorCanvas {...props} />
        </Suspense>
    );
}
