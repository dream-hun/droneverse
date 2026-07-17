import { OrbitControls, Sky } from '@react-three/drei';
import { Canvas } from '@react-three/fiber';
import { Physics } from '@react-three/rapier';
import type { RapierRigidBody } from '@react-three/rapier';
import { useRef, useState } from 'react';
import { CameraRig, DEFAULT_FOV } from '@/components/simulator/camera-rig';
import type { CameraMode } from '@/components/simulator/camera-rig';
import { DroneRig } from '@/components/simulator/drone-rig';
import { EnvironmentObjects } from '@/components/simulator/environment-objects';
import { FlightHud } from '@/components/simulator/flight-hud';
import { Button } from '@/components/ui/button';
import { useCanvasResizeFix } from '@/hooks/use-canvas-resize-fix';
import { createFlightVisualState } from '@/lib/simulator/flight-state';
import type { SimulatorSession } from '@/lib/simulator/session';
import type { EnvironmentConfig, SuccessCriteria } from '@/types/simulator';

type SimulatorCanvasProps = {
    session: SimulatorSession;
    environment: EnvironmentConfig;
    successCriteria: SuccessCriteria;
    maxScore: number;
    attemptUrl: string;
};

const CAMERA_MODES: { id: CameraMode; label: string }[] = [
    { id: 'orbit', label: 'Free' },
    { id: 'follow', label: 'Follow' },
    { id: 'fpv', label: 'FPV' },
];

// One late-morning sun direction shared by the sky shader and the shadow light.
const SUN_DIRECTION: [number, number, number] = [0.75, 1, 0.46];

export function SimulatorCanvas({
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
                    position: [span * 0.45, span * 0.35, span * 0.55],
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

            <div className="absolute top-2 right-2 z-10 flex gap-1 rounded-lg border bg-background/80 p-1 shadow-sm backdrop-blur">
                {CAMERA_MODES.map((mode) => (
                    <Button
                        key={mode.id}
                        size="sm"
                        variant={cameraMode === mode.id ? 'secondary' : 'ghost'}
                        className="h-7 px-2 text-xs"
                        onClick={() => setCameraMode(mode.id)}
                    >
                        {mode.label}
                    </Button>
                ))}
            </div>

            <FlightHud flightState={flightState} />
        </div>
    );
}
