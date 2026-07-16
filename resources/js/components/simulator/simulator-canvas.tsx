import { OrbitControls } from '@react-three/drei';
import { Canvas } from '@react-three/fiber';
import { Physics } from '@react-three/rapier';
import { useRef } from 'react';
import { DroneRig } from '@/components/simulator/drone-rig';
import { EnvironmentObjects } from '@/components/simulator/environment-objects';
import { useCanvasResizeFix } from '@/hooks/use-canvas-resize-fix';
import type { SimulatorSession } from '@/lib/simulator/session';
import type { EnvironmentConfig, SuccessCriteria } from '@/types/simulator';

type SimulatorCanvasProps = {
    session: SimulatorSession;
    environment: EnvironmentConfig;
    successCriteria: SuccessCriteria;
    maxScore: number;
    attemptUrl: string;
};

export function SimulatorCanvas({ environment, ...props }: SimulatorCanvasProps) {
    const span = Math.max(environment.bounds.width, environment.bounds.depth);
    const containerRef = useRef<HTMLDivElement>(null);
    useCanvasResizeFix(containerRef);

    return (
        <div ref={containerRef} className="h-full w-full">
            <Canvas
                shadows
                camera={{
                    position: [span * 0.5, span * 0.45, span * 0.5],
                    fov: 45,
                }}
            >
                <color attach="background" args={['#0b1120']} />
                <ambientLight intensity={0.7} />
                <directionalLight
                    position={[10, 18, 8]}
                    intensity={1.1}
                    castShadow
                />
                <Physics gravity={[0, -9.81, 0]}>
                    <EnvironmentObjects environment={environment} />
                    <DroneRig environment={environment} {...props} />
                </Physics>
                <gridHelper args={[span, 20, '#334155', '#1e293b']} />
                <OrbitControls
                    makeDefault
                    target={[environment.start.x, 1, environment.start.z]}
                />
            </Canvas>
        </div>
    );
}
