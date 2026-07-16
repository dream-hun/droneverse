import { RigidBody } from '@react-three/rapier';
import type { EnvironmentConfig, GateConfig } from '@/types/simulator';

function GateFrame({ gate }: { gate: GateConfig }) {
    const postThickness = 0.15;

    return (
        <group position={[gate.x, 0, gate.z]} rotation={[0, gate.rotationY, 0]}>
            <mesh position={[-gate.width / 2, gate.height / 2, 0]} castShadow>
                <boxGeometry
                    args={[postThickness, gate.height, postThickness]}
                />
                <meshStandardMaterial color="#facc15" />
            </mesh>
            <mesh position={[gate.width / 2, gate.height / 2, 0]} castShadow>
                <boxGeometry
                    args={[postThickness, gate.height, postThickness]}
                />
                <meshStandardMaterial color="#facc15" />
            </mesh>
            <mesh position={[0, gate.height, 0]} castShadow>
                <boxGeometry
                    args={[
                        gate.width + postThickness,
                        postThickness,
                        postThickness,
                    ]}
                />
                <meshStandardMaterial color="#facc15" />
            </mesh>
        </group>
    );
}

export function EnvironmentObjects({
    environment,
}: {
    environment: EnvironmentConfig;
}) {
    return (
        <>
            <RigidBody
                type="fixed"
                colliders="cuboid"
                userData={{ kind: 'ground' }}
            >
                <mesh receiveShadow rotation-x={-Math.PI / 2}>
                    <planeGeometry
                        args={[
                            environment.bounds.width,
                            environment.bounds.depth,
                        ]}
                    />
                    <meshStandardMaterial color="#1e293b" />
                </mesh>
            </RigidBody>

            {environment.obstacles.map((obstacle, index) => (
                <RigidBody
                    key={index}
                    type="fixed"
                    colliders="cuboid"
                    position={[obstacle.x, obstacle.y, obstacle.z]}
                    rotation={[0, obstacle.rotationY ?? 0, 0]}
                    userData={{ kind: 'obstacle' }}
                >
                    <mesh castShadow>
                        <boxGeometry
                            args={[
                                obstacle.sx ?? 1,
                                obstacle.sy ?? 1,
                                obstacle.sz ?? 1,
                            ]}
                        />
                        <meshStandardMaterial color="#f97316" />
                    </mesh>
                </RigidBody>
            ))}

            {environment.gates.map((gate, index) => (
                <GateFrame key={index} gate={gate} />
            ))}

            {environment.waypoints.map((waypoint, index) => (
                <mesh
                    key={index}
                    position={[waypoint.x, waypoint.y, waypoint.z]}
                >
                    <sphereGeometry args={[0.3, 16, 16]} />
                    <meshStandardMaterial
                        color="#22d3ee"
                        emissive="#22d3ee"
                        emissiveIntensity={0.6}
                        transparent
                        opacity={0.7}
                    />
                </mesh>
            ))}

            <mesh
                position={[environment.goal.x, 0.02, environment.goal.z]}
                rotation-x={-Math.PI / 2}
            >
                <circleGeometry args={[environment.goal.radius, 32]} />
                <meshStandardMaterial
                    color="#22c55e"
                    transparent
                    opacity={0.5}
                />
            </mesh>
        </>
    );
}
