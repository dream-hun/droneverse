import { OrbitControls } from '@react-three/drei';
import { Canvas } from '@react-three/fiber';
import { useRef } from 'react';
import { DroneModel } from '@/components/simulator/drone-model';
import { useCanvasResizeFix } from '@/hooks/use-canvas-resize-fix';

export function DroneShowcase() {
    const containerRef = useRef<HTMLDivElement>(null);
    useCanvasResizeFix(containerRef);

    return (
        <div ref={containerRef} className="h-full w-full">
            <Canvas camera={{ position: [2.6, 1.9, 2.6], fov: 40 }}>
                <ambientLight intensity={0.9} />
                <directionalLight position={[4, 6, 3]} intensity={1.3} />
                <group scale={1.7}>
                    <DroneModel />
                </group>
                <OrbitControls
                    autoRotate
                    autoRotateSpeed={2.5}
                    enableZoom={false}
                    enablePan={false}
                />
            </Canvas>
        </div>
    );
}
