import { useFrame } from '@react-three/fiber';
import { useRef } from 'react';
import type { Mesh } from 'three';

const ARM_POSITIONS: [number, number, number][] = [
    [0.35, 0, 0.35],
    [-0.35, 0, 0.35],
    [0.35, 0, -0.35],
    [-0.35, 0, -0.35],
];

export function DroneModel() {
    const rotorRefs = useRef<(Mesh | null)[]>([]);

    useFrame((_, dt) => {
        rotorRefs.current.forEach((rotor) => {
            rotor?.rotateY(dt * 25);
        });
    });

    return (
        <group>
            <mesh castShadow>
                <boxGeometry args={[0.5, 0.15, 0.5]} />
                <meshStandardMaterial color="#38bdf8" />
            </mesh>

            {ARM_POSITIONS.map((position, index) => (
                <group key={index} position={position}>
                    <mesh castShadow>
                        <cylinderGeometry args={[0.05, 0.05, 0.1, 8]} />
                        <meshStandardMaterial color="#0f172a" />
                    </mesh>
                    <mesh
                        ref={(mesh) => {
                            rotorRefs.current[index] = mesh;
                        }}
                        position={[0, 0.08, 0]}
                    >
                        <cylinderGeometry args={[0.25, 0.25, 0.02, 16]} />
                        <meshStandardMaterial
                            color="#0f172a"
                            transparent
                            opacity={0.45}
                        />
                    </mesh>
                </group>
            ))}

            {/* Faces -Z, matching the yaw=0 forward direction used by the simulation engine. */}
            <mesh position={[0, 0.05, -0.3]} rotation-x={-Math.PI / 2}>
                <coneGeometry args={[0.08, 0.2, 8]} />
                <meshStandardMaterial color="#ef4444" />
            </mesh>
        </group>
    );
}
