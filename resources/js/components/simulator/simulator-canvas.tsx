import { OrbitControls, Sky } from '@react-three/drei';
import { Canvas } from '@react-three/fiber';
import { Physics } from '@react-three/rapier';
import type { RapierRigidBody } from '@react-three/rapier';
import { memo, useRef, useState } from 'react';
import { CameraRig, DEFAULT_FOV } from '@/components/simulator/camera-rig';
import type { CameraMode } from '@/components/simulator/camera-rig';
import { DroneRig } from '@/components/simulator/drone-rig';
import { EnvironmentObjects } from '@/components/simulator/environment-objects';
import { FlightHud } from '@/components/simulator/flight-hud';
import { useCanvasResizeFix } from '@/hooks/use-canvas-resize-fix';
import { createFlightVisualState } from '@/lib/simulator/flight-state';
import type { SimulatorSession } from '@/lib/simulator/session';
import { cn } from '@/lib/utils';
import type { EnvironmentConfig, SuccessCriteria } from '@/types/simulator';

type SimulatorCanvasProps = {
    session: SimulatorSession;
    environment: EnvironmentConfig;
    successCriteria: SuccessCriteria;
    maxScore: number;
    attemptUrl: string;
    photoUrl: string;
};

const CAMERA_MODES: { id: CameraMode; label: string }[] = [
    { id: 'orbit', label: 'FREE' },
    { id: 'follow', label: 'FOLLOW' },
    { id: 'fpv', label: 'FPV' },
];

// One late-morning sun direction shared by the sky shader and the shadow light.
const SUN_DIRECTION: [number, number, number] = [0.75, 1, 0.46];

/**
 * Memoised because the page above it owns the editor buffer.
 *
 * Every keystroke in Monaco is a `setState` on the mission page, so without
 * this the viewport re-rendered on each one — and a re-render here is not
 * cheap markup, it is React reconciling the whole R3F tree: the physics
 * world, the drone rig, and every mesh in the city below. The pilot paid
 * for that in the editor, as typing latency, on the page where they spend
 * the most time typing.
 *
 * Every prop is stable across those renders: `session` and `environment`
 * come from `useState`/Inertia page props by reference, the URLs are equal
 * strings, and `maxScore` is a number — so the shallow compare bails.
 */
function SimulatorCanvasComponent({
    environment,
    ...props
}: SimulatorCanvasProps) {
    const span = Math.max(environment.bounds.width, environment.bounds.depth);
    const containerRef = useRef<HTMLDivElement>(null);
    const rigidBodyRef = useRef<RapierRigidBody>(null);
    const [flightState] = useState(createFlightVisualState);
    const [cameraMode, setCameraMode] = useState<CameraMode>('orbit');
    useCanvasResizeFix(containerRef);

    return (
        <div ref={containerRef} className="relative h-full w-full">
            <Canvas
                shadows
                camera={{
                    // Framed on the launch pad rather than on the field's
                    // origin. OrbitControls targets the pad, so an offset
                    // measured from the origin put the drone off to one side
                    // — and hard against the frame edge on the missions whose
                    // pad sits in a corner of the bounds.
                    position: [
                        environment.start.x + span * 0.3,
                        span * 0.28,
                        environment.start.z + span * 0.36,
                    ],
                    fov: DEFAULT_FOV,
                    near: 0.1,
                    far: span * 12,
                }}
            >
                <color attach="background" args={['#87b7dd']} />
                <fog attach="fog" args={['#c6d8e7', span * 1.1, span * 3.4]} />
                <Sky
                    sunPosition={SUN_DIRECTION}
                    turbidity={5.5}
                    rayleigh={1.7}
                    mieCoefficient={0.004}
                    mieDirectionalG={0.85}
                />
                <hemisphereLight args={['#cfe3f7', '#5d6b4f', 0.6]} />
                <directionalLight
                    position={[
                        SUN_DIRECTION[0] * span,
                        SUN_DIRECTION[1] * span,
                        SUN_DIRECTION[2] * span,
                    ]}
                    intensity={2}
                    castShadow
                    shadow-mapSize={[2048, 2048]}
                    shadow-bias={-0.0003}
                    shadow-normalBias={0.02}
                    shadow-camera-near={1}
                    shadow-camera-far={span * 4}
                    shadow-camera-left={-span * 0.8}
                    shadow-camera-right={span * 0.8}
                    shadow-camera-top={span * 0.8}
                    shadow-camera-bottom={-span * 0.8}
                />
                {/* Shadowless fill from the anti-sun side. The airframe is
                    carbon black on almost every face, so lit from one
                    direction it reads as a silhouette against the field
                    rather than as a machine with edges. */}
                <directionalLight
                    position={[
                        -SUN_DIRECTION[0] * span,
                        SUN_DIRECTION[1] * span * 0.4,
                        -SUN_DIRECTION[2] * span,
                    ]}
                    intensity={0.45}
                />
                <Physics gravity={[0, -9.81, 0]}>
                    <EnvironmentObjects environment={environment} />
                    <DroneRig
                        rigidBodyRef={rigidBodyRef}
                        flightState={flightState}
                        environment={environment}
                        {...props}
                    />
                </Physics>
                <CameraRig
                    mode={cameraMode}
                    rigidBodyRef={rigidBodyRef}
                    flightState={flightState}
                />
                {cameraMode === 'orbit' && (
                    <OrbitControls
                        makeDefault
                        target={[environment.start.x, 1, environment.start.z]}
                        maxPolarAngle={Math.PI / 2 - 0.03}
                        minDistance={1.5}
                        maxDistance={span * 2.5}
                    />
                )}
            </Canvas>

            {/* Above the HUD's z-10 so the one piece of chrome that takes
                clicks is never the piece underneath. */}
            <div
                role="group"
                aria-label="Camera view"
                className="absolute top-2 right-2 z-20 flex gap-0.5 rounded-md border border-white/10 bg-[#0b1016]/80 p-0.5 font-mono backdrop-blur-sm"
            >
                {CAMERA_MODES.map((mode) => (
                    <button
                        key={mode.id}
                        type="button"
                        aria-pressed={cameraMode === mode.id}
                        className={cn(
                            'rounded px-2.5 py-1 text-[10px] font-medium tracking-[0.14em] transition-colors',
                            cameraMode === mode.id
                                ? 'bg-white/15 text-cyan-300'
                                : 'text-slate-400 hover:bg-white/5 hover:text-slate-200',
                        )}
                        onClick={() => setCameraMode(mode.id)}
                    >
                        {mode.label}
                    </button>
                ))}
            </div>

            <FlightHud flightState={flightState} />
        </div>
    );
}

export const SimulatorCanvas = memo(SimulatorCanvasComponent);
