// @vitest-environment jsdom
//
// Per file rather than in vitest.config.ts: this is the only suite that
// mounts a component, and the rest exercise plain modules that have no use
// for a DOM and should not pay a second and a half to stand one up.

import { act, cleanup, render, screen } from '@testing-library/react';
import { Suspense, useEffect, useLayoutEffect, useState } from 'react';
import type { ReactNode } from 'react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { SimulatorCanvas } from '@/components/simulator/simulator-canvas';
import { SimulatorSession } from '@/lib/simulator/session';
import type { DroneModelSummary } from '@/types/drone';
import type { EnvironmentConfig, SuccessCriteria } from '@/types/simulator';

/**
 * What these tests watch for, and why it is the hiding rather than the
 * context loss itself.
 *
 * The bug ends in `forceContextLoss()`: react-three-fiber's canvas cleanup
 * calls `unmountComponentAtNode`, which waits half a second and then kills
 * the renderer's WebGL context, so the viewport goes black under a HUD that
 * carries on working. None of that end is reachable here — jsdom has no WebGL
 * to lose, and React's teardown of a hidden tree's passive effects lands in a
 * later flush than a test can drive.
 *
 * Its cause is reachable, and is the thing worth pinning: the scene suspends
 * past the canvas, so React hides a canvas it has already committed. Hiding
 * is what puts the teardown in reach; a canvas that is never hidden is never
 * torn down. Both are observable — a fallback outside the canvas rendering,
 * and `display: none` on the canvas itself.
 */

/** Resolves the pending physics-engine load, mirroring the WASM arriving. */
let loadPhysics: () => void;

/**
 * A stand-in for `<Canvas>` that keeps the two behaviours under test.
 *
 * Fiber wraps the scene in its own Suspense boundary whose fallback, `Block`,
 * sets state on the canvas that makes it re-throw on the next render — so a
 * child suspending does not stop at the canvas, it suspends the canvas too,
 * and React tears down the effects of a tree it has already committed. That
 * relay is modelled faithfully because it is the mechanism the fix defeats;
 * the renderer, the scene graph and the WebGL context are all irrelevant to
 * it and are left out.
 *
 * The scene also has to arrive a beat after the canvas itself, as it does in
 * fiber, where `root.render()` waits on the renderer's configure promise
 * before handing the children to its own reconciler. Rendering them in the
 * same pass would let the scene suspend before the canvas had finished
 * mounting — and a boundary that never mounted its children has no effects to
 * destroy, so the teardown this test counts could not happen either way.
 */
function FakeCanvas({ children }: { children: ReactNode }) {
    const [block, setBlock] = useState<Promise<void> | false>(false);
    const [mounted, setMounted] = useState(false);

    // The cascading render is the point — see above. Fiber hands the scene to
    // its reconciler a pass after the canvas mounts, and a test that skipped
    // that would let the scene suspend before there was a canvas to hide.
    // eslint-disable-next-line react-hooks/set-state-in-effect
    useEffect(() => setMounted(true), []);

    if (block) {
        throw block;
    }

    return (
        <div data-testid="canvas">
            <Suspense fallback={<FakeBlock set={setBlock} />}>
                {mounted ? children : null}
            </Suspense>
        </div>
    );
}

function FakeBlock({ set }: { set: (block: Promise<void>) => void }) {
    useLayoutEffect(() => {
        set(new Promise<void>(() => undefined));
    }, [set]);

    return null;
}

/**
 * A `<Physics>` that suspends once, as the real one does.
 *
 * `@react-three/rapier` loads its WebAssembly binary through `suspend-react`,
 * so the first render of the physics world always throws a promise. That the
 * suspension happens at all is the whole premise of the test, so the promise
 * is held open until the test resolves it deliberately.
 */
let physicsLoaded = false;
let physicsPromise: Promise<void>;

function FakePhysics({ children }: { children: ReactNode }) {
    if (!physicsLoaded) {
        throw physicsPromise;
    }

    return <div data-testid="scene">{children}</div>;
}

/**
 * The last props `<Canvas>` was rendered with, for the settings tests.
 *
 * Cleared between tests. It is written only from inside the mock, so a test
 * whose render stopped reaching `<Canvas>` at all would otherwise assert
 * happily against the props the previous test left behind.
 */
let canvasProps: Record<string, unknown> = {};

vi.mock('@react-three/fiber', () => ({
    Canvas: ({ children, ...props }: { children: ReactNode }) => {
        canvasProps = props;

        return <FakeCanvas>{children}</FakeCanvas>;
    },
    useFrame: () => undefined,
    useThree: () => ({}),
}));

vi.mock('@react-three/rapier', () => ({
    Physics: (props: { children: ReactNode }) => <FakePhysics {...props} />,
}));

vi.mock('@react-three/drei', () => ({
    OrbitControls: () => null,
    Sky: () => null,
}));

/**
 * The scene's contents are stubbed out: none of them decide whether the
 * canvas survives a suspending child, and mounting the real ones would drag
 * three.js into a DOM with no WebGL behind it.
 */
vi.mock('@/components/simulator/camera-rig', () => ({
    CameraRig: () => null,
    DEFAULT_FOV: 50,
}));

vi.mock('@/components/simulator/drone-rig', () => ({
    DroneRig: () => null,
}));

vi.mock('@/components/simulator/environment-objects', () => ({
    EnvironmentObjects: () => null,
}));

vi.mock('@/components/simulator/flight-hud', () => ({
    FlightHud: () => null,
}));

function environment(): EnvironmentConfig {
    return {
        start: { x: 0, y: 0, z: 0, yaw: 0 },
        bounds: { width: 40, depth: 40, height: 20 },
        obstacles: [],
        gates: [],
        waypoints: [],
        goal: { x: 0, z: 0, radius: 1 },
    };
}

function successCriteria(): SuccessCriteria {
    return {
        type: 'waypoints',
        waypoints: [],
        avoid_collisions: true,
        max_time_seconds: 60,
        landing_required: true,
    };
}

function renderCanvas() {
    return render(
        <Suspense fallback={<div data-testid="outer-fallback" />}>
            <SimulatorCanvas
                session={new SimulatorSession()}
                drone={{} as DroneModelSummary}
                environment={environment()}
                successCriteria={successCriteria()}
                maxScore={100}
                attemptUrl="/attempts"
                photoUrl="/photos"
            />
        </Suspense>,
    );
}

describe('SimulatorCanvas', () => {
    beforeEach(() => {
        canvasProps = {};
        physicsLoaded = false;
        physicsPromise = new Promise<void>((resolve) => {
            loadPhysics = () => {
                physicsLoaded = true;
                resolve();
            };
        });

        // The canvas measures its container through one of these, and jsdom
        // ships neither.
        vi.stubGlobal(
            'ResizeObserver',
            class {
                observe() {}
                unobserve() {}
                disconnect() {}
            },
        );
    });

    afterEach(() => {
        cleanup();
        vi.unstubAllGlobals();
    });

    it('holds the physics engine load inside the canvas', () => {
        renderCanvas();

        // The scene is still waiting on the engine, and the boundary outside
        // the canvas has not noticed: the suspension stopped short of it.
        expect(screen.queryByTestId('scene')).toBeNull();
        expect(screen.queryByTestId('outer-fallback')).toBeNull();
    });

    it('leaves the canvas on screen while the physics engine loads', () => {
        const { container } = renderCanvas();

        // React hides what it has committed under a boundary that re-suspends,
        // and a hidden canvas is a canvas fiber is free to tear down. Which
        // node it hides is React's business — that nothing is hidden is the
        // claim.
        expect(container.querySelector('[style*="display: none"]')).toBeNull();
    });

    it('asks for a shadow map three.js has not deprecated', () => {
        renderCanvas();

        // `shadows` on its own, and `shadows="soft"`, both mean
        // PCFSoftShadowMap — which three 0.185 replaces with PCFShadowMap
        // behind a warning it repeats on every configure. Anything that puts
        // one of those back belongs in this failure, not in the console.
        expect(canvasProps.shadows).toBe('percentage');
    });

    it('renders the scene once the physics engine arrives', async () => {
        const { getByTestId } = renderCanvas();
        const canvas = getByTestId('canvas');

        await act(async () => {
            loadPhysics();
        });

        // Same canvas, now with a scene in it: the wait cost nothing.
        expect(screen.getByTestId('canvas')).toBe(canvas);
        expect(screen.getByTestId('scene')).toBeDefined();
    });
});
