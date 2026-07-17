import { useFrame } from '@react-three/fiber';
import {
    CuboidCollider,
    CylinderCollider,
    RigidBody,
} from '@react-three/rapier';
import { useEffect, useMemo, useRef } from 'react';
import type { Group } from 'three';
import {
    createFieldTexture,
    createGrassTexture,
    createHazardTexture,
    createHelipadTexture,
    createTargetPadTexture,
} from '@/lib/simulator/textures';
import type {
    EnvironmentConfig,
    GateConfig,
    ObstacleConfig,
    WaypointConfig,
} from '@/types/simulator';

const OBSTACLE_PALETTE = ['#c9803d', '#a8b0ba', '#b9a26b'];

function GateFrame({ gate }: { gate: GateConfig }) {
    const postRadius = 0.06;
    const bandHeights = [0.35, 0.7];

    return (
        <group position={[gate.x, 0, gate.z]} rotation={[0, gate.rotationY, 0]}>
            {[-1, 1].map((side) => (
                <group key={side} position={[(side * gate.width) / 2, 0, 0]}>
                    <mesh position={[0, gate.height / 2, 0]} castShadow>
                        <cylinderGeometry
                            args={[postRadius, postRadius, gate.height, 12]}
                        />
                        <meshStandardMaterial
                            color="#f0b429"
                            roughness={0.55}
                        />
                    </mesh>
                    {bandHeights.map((ratio) => (
                        <mesh
                            key={ratio}
                            position={[0, gate.height * ratio, 0]}
                        >
                            <cylinderGeometry args={[0.075, 0.075, 0.14, 12]} />
                            <meshStandardMaterial
                                color="#7c5410"
                                emissive="#fbbf24"
                                emissiveIntensity={1.1}
                            />
                        </mesh>
                    ))}
                    <mesh position={[0, 0.015, 0]} receiveShadow>
                        <boxGeometry args={[0.45, 0.03, 0.45]} />
                        <meshStandardMaterial color="#272c31" roughness={0.8} />
                    </mesh>
                </group>
            ))}
            <mesh
                position={[0, gate.height, 0]}
                rotation-z={Math.PI / 2}
                castShadow
            >
                <cylinderGeometry
                    args={[postRadius, postRadius, gate.width + 0.3, 12]}
                />
                <meshStandardMaterial color="#f0b429" roughness={0.55} />
            </mesh>
        </group>
    );
}

function Obstacle({
    obstacle,
    index,
}: {
    obstacle: ObstacleConfig;
    index: number;
}) {
    const isCylinder = obstacle.type === 'cylinder';
    const sx = obstacle.sx ?? 1;
    const sy = obstacle.sy ?? 1;
    const sz = obstacle.sz ?? 1;
    const radius = obstacle.radius ?? 0.5;
    const height = obstacle.height ?? sy;
    const isPylon = !isCylinder && sy >= 2 && Math.min(sx, sz) <= 1.6;

    const hazardMap = useMemo(() => {
        if (!isPylon) {
            return null;
        }

        const texture = createHazardTexture();
        texture.repeat.set(
            Math.max(1, Math.round((sx + sz) / 2)),
            Math.max(1, Math.round(sy / 1.2)),
        );

        return texture;
    }, [isPylon, sx, sy, sz]);

    useEffect(() => () => hazardMap?.dispose(), [hazardMap]);

    return (
        <RigidBody
            type="fixed"
            colliders={false}
            position={[obstacle.x, obstacle.y, obstacle.z]}
            rotation={[0, obstacle.rotationY ?? 0, 0]}
            userData={{ kind: 'obstacle' }}
        >
            {isCylinder ? (
                <CylinderCollider args={[height / 2, radius]} />
            ) : (
                <CuboidCollider args={[sx / 2, sy / 2, sz / 2]} />
            )}
            <mesh castShadow receiveShadow>
                {isCylinder ? (
                    <cylinderGeometry args={[radius, radius, height, 24]} />
                ) : (
                    <boxGeometry args={[sx, sy, sz]} />
                )}
                {hazardMap ? (
                    <meshStandardMaterial map={hazardMap} roughness={0.75} />
                ) : (
                    <meshStandardMaterial
                        color={
                            OBSTACLE_PALETTE[index % OBSTACLE_PALETTE.length]
                        }
                        roughness={0.85}
                    />
                )}
            </mesh>
        </RigidBody>
    );
}

/** Holographic capture ring: honest about the waypoint's trigger radius. */
function WaypointBeacon({
    waypoint,
    index,
}: {
    waypoint: WaypointConfig;
    index: number;
}) {
    const groupRef = useRef<Group>(null);

    useFrame(({ clock }) => {
        const group = groupRef.current;

        if (!group) {
            return;
        }

        const seconds = clock.elapsedTime;
        group.position.y =
            waypoint.y + Math.sin(seconds * 1.3 + index * 1.7) * 0.08;
        group.rotation.y = seconds * 0.5;
    });

    return (
        <group ref={groupRef} position={[waypoint.x, waypoint.y, waypoint.z]}>
            <mesh rotation-x={Math.PI / 2}>
                <torusGeometry args={[waypoint.radius, 0.035, 12, 48]} />
                <meshStandardMaterial
                    color="#22d3ee"
                    emissive="#22d3ee"
                    emissiveIntensity={1.1}
                    transparent
                    opacity={0.55}
                />
            </mesh>
            <mesh>
                <sphereGeometry args={[0.12, 16, 16]} />
                <meshStandardMaterial
                    color="#22d3ee"
                    emissive="#22d3ee"
                    emissiveIntensity={2}
                    transparent
                    opacity={0.85}
                />
            </mesh>
        </group>
    );
}

export function EnvironmentObjects({
    environment,
}: {
    environment: EnvironmentConfig;
}) {
    const span = Math.max(environment.bounds.width, environment.bounds.depth);

    const textures = useMemo(
        () => ({
            field: createFieldTexture(
                environment.bounds.width,
                environment.bounds.depth,
            ),
            grass: (() => {
                const grass = createGrassTexture();
                grass.repeat.set(span * 2.5, span * 2.5);

                return grass;
            })(),
            helipad: createHelipadTexture(),
            target: createTargetPadTexture(),
        }),
        [environment.bounds.depth, environment.bounds.width, span],
    );

    useEffect(
        () => () => {
            Object.values(textures).forEach((texture) => texture.dispose());
        },
        [textures],
    );

    return (
        <>
            <RigidBody
                type="fixed"
                colliders="cuboid"
                userData={{ kind: 'ground' }}
            >
                <mesh
                    receiveShadow
                    rotation-x={-Math.PI / 2}
                    position={[0, -0.02, 0]}
                >
                    <planeGeometry args={[span * 8, span * 8]} />
                    <meshStandardMaterial
                        map={textures.grass}
                        color="#9aa78a"
                        roughness={1}
                    />
                </mesh>
                <mesh receiveShadow rotation-x={-Math.PI / 2}>
                    <planeGeometry
                        args={[
                            environment.bounds.width,
                            environment.bounds.depth,
                        ]}
                    />
                    <meshStandardMaterial
                        map={textures.field}
                        roughness={0.95}
                    />
                </mesh>
            </RigidBody>

            {/* Launch helipad */}
            <mesh
                position={[environment.start.x, 0.012, environment.start.z]}
                rotation-x={-Math.PI / 2}
                receiveShadow
            >
                <circleGeometry args={[1.15, 48]} />
                <meshStandardMaterial
                    map={textures.helipad}
                    transparent
                    roughness={0.9}
                />
            </mesh>

            {/* Goal landing target */}
            <mesh
                position={[environment.goal.x, 0.011, environment.goal.z]}
                rotation-x={-Math.PI / 2}
                receiveShadow
            >
                <circleGeometry args={[environment.goal.radius, 48]} />
                <meshStandardMaterial
                    map={textures.target}
                    transparent
                    opacity={0.92}
                    roughness={0.9}
                />
            </mesh>

            {environment.obstacles.map((obstacle, index) => (
                <Obstacle key={index} obstacle={obstacle} index={index} />
            ))}

            {environment.gates.map((gate, index) => (
                <GateFrame key={index} gate={gate} />
            ))}

            {environment.waypoints.map((waypoint, index) => (
                <WaypointBeacon key={index} waypoint={waypoint} index={index} />
            ))}
        </>
    );
}
