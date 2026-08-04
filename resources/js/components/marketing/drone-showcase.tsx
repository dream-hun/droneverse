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
                {/* Three-point rig rather than a key and a wash. The airframe
                    is carbon black on almost every face and this section is
                    dark behind it, so a single key leaves the drone reading
                    as a hole in the panel: the rim picks its silhouette off
                    the background and the fill keeps the shaded side from
                    going flat. */}
                <ambientLight intensity={0.55} />
                <directionalLight position={[4, 6, 3]} intensity={2.2} />
                <directionalLight position={[-5, 2, -4]} intensity={1.4} />
                <directionalLight position={[0, -3, 5]} intensity={0.5} />
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
