import { useFrame } from '@react-three/fiber';
import {
    CuboidCollider,
    CylinderCollider,
    RigidBody,
} from '@react-three/rapier';
import { useEffect, useMemo, useRef } from 'react';
import type { ReactNode } from 'react';
import type { Group } from 'three';
import {
    createConcreteTexture,
    createCrateTexture,
    createFacadeTexture,
    createFieldTexture,
    createGrassTexture,
    createHazardTexture,
    createHelipadTexture,
    createRoofTexture,
    createTargetPadTexture,
} from '@/lib/simulator/textures';
import type {
    EnvironmentConfig,
    GateConfig,
    ObstacleConfig,
    WaypointConfig,
} from '@/types/simulator';

type ObstacleKind = 'building' | 'crate' | 'pylon' | 'wall' | 'cylinder' | 'block';

/**
 * Decides how a raw collision box should look. Sizes come straight from the
 * challenge config, so the same footprint always reads the same way: tall
 * chunky boxes become glazed towers, small ones become cargo crates, thin
 * tall posts get hazard stripes, and long thin boxes become concrete walls.
 * The physics collider is unchanged regardless of which visual wins.
 */
function classifyObstacle(obstacle: ObstacleConfig): ObstacleKind {
    if (obstacle.type === 'cylinder') {
        return 'cylinder';
    }

    const sx = obstacle.sx ?? 1;
    const sy = obstacle.sy ?? 1;
    const sz = obstacle.sz ?? 1;
    const footprint = Math.min(sx, sz);
    const span = Math.max(sx, sz);

    if (sy >= 4 && footprint >= 2.5) {
        return 'building';
    }

    if (footprint <= 1.2 && span >= 3) {
        return 'wall';
    }

    if (footprint <= 1.6 && sy >= 2) {
        return 'pylon';
    }

    if (sy <= 2.5 && sx <= 2.2 && sz <= 2.2) {
        return 'crate';
    }

    return 'block';
}

/** Stable per-obstacle seed so a tower's windows never flicker between frames. */
function obstacleSeed(obstacle: ObstacleConfig): number {
    const x = Math.round(obstacle.x * 100);
    const z = Math.round(obstacle.z * 100);
    const h = Math.round((obstacle.sy ?? 1) * 100);

    return Math.abs((x * 73856093) ^ (z * 19349663) ^ (h * 83492791)) >>> 0;
}

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

/** Wraps any obstacle visual in its fixed rigid body + matching collider. */
function ObstacleBody({
    obstacle,
    children,
}: {
    obstacle: ObstacleConfig;
    children: ReactNode;
}) {
    const isCylinder = obstacle.type === 'cylinder';
    const sx = obstacle.sx ?? 1;
    const sy = obstacle.sy ?? 1;
    const sz = obstacle.sz ?? 1;
    const radius = obstacle.radius ?? 0.5;
    const height = obstacle.height ?? sy;

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
            {children}
        </RigidBody>
    );
}

function seededRng(seed: number): () => number {
    let state = seed >>> 0;

    return () => {
        state = (state + 0x6d2b79f5) | 0;
        let t = Math.imul(state ^ (state >>> 15), 1 | state);
        t = (t + Math.imul(t ^ (t >>> 7), 61 | t)) ^ t;

        return ((t ^ (t >>> 14)) >>> 0) / 4294967296;
    };
}

/** Air handlers, vents, and a mast scattered on a tower roof. */
function RooftopClutter({
    sx,
    sz,
    topY,
    seed,
}: {
    sx: number;
    sz: number;
    topY: number;
    seed: number;
}) {
    const items = useMemo(() => {
        const rng = seededRng(seed ^ 0x9e3779b9);
        const marginX = sx / 2 - 1;
        const marginZ = sz / 2 - 1;
        const count = 2 + Math.floor(rng() * 2);
        const result: {
            key: number;
            position: [number, number, number];
            size: [number, number, number];
            color: string;
        }[] = [];

        for (let i = 0; i < count; i++) {
            const w = 0.8 + rng() * 1.4;
            const d = 0.8 + rng() * 1.2;
            const h = 0.5 + rng() * 0.8;
            result.push({
                key: i,
                position: [
                    (rng() * 2 - 1) * marginX,
                    topY + h / 2,
                    (rng() * 2 - 1) * marginZ,
                ],
                size: [w, h, d],
                color: rng() > 0.5 ? '#b7bcc2' : '#8b9096',
            });
        }

        return result;
    }, [sx, sz, topY, seed]);

    return (
        <>
            {items.map((item) => (
                <mesh
                    key={item.key}
                    position={item.position}
                    castShadow
                    receiveShadow
                >
                    <boxGeometry args={item.size} />
                    <meshStandardMaterial color={item.color} roughness={0.7} />
                </mesh>
            ))}
            {/* Antenna mast with a warning light. */}
            <mesh position={[sx * 0.28, topY + 1.1, -sz * 0.28]} castShadow>
                <cylinderGeometry args={[0.04, 0.04, 2.2, 8]} />
                <meshStandardMaterial color="#2c3036" metalness={0.6} />
            </mesh>
            <mesh position={[sx * 0.28, topY + 2.25, -sz * 0.28]}>
                <sphereGeometry args={[0.09, 10, 10]} />
                <meshStandardMaterial
                    color="#4a0f0c"
                    emissive="#ff2a1f"
                    emissiveIntensity={1.6}
                />
            </mesh>
        </>
    );
}

const PARAPET_COLOR = '#7b818a';

/** A glazed tower: solid mass, windowed facades, gravel roof, parapet, clutter. */
function BuildingVisual({
    obstacle,
    seed,
}: {
    obstacle: ObstacleConfig;
    seed: number;
}) {
    const sx = obstacle.sx ?? 1;
    const sy = obstacle.sy ?? 1;
    const sz = obstacle.sz ?? 1;
    const hx = sx / 2;
    const hy = sy / 2;
    const hz = sz / 2;

    // Front/back faces span the width; left/right span the depth. Two textures
    // (seeded apart) keep adjacent faces from looking copy-pasted.
    const facadeWide = useMemo(
        () => createFacadeTexture(sx, sy, seed),
        [sx, sy, seed],
    );
    const facadeDeep = useMemo(
        () => createFacadeTexture(sz, sy, seed ^ 0x1234),
        [sz, sy, seed],
    );
    const roofMap = useMemo(() => createRoofTexture(seed), [seed]);

    useEffect(
        () => () => {
            facadeWide.dispose();
            facadeDeep.dispose();
            roofMap.dispose();
        },
        [facadeWide, facadeDeep, roofMap],
    );

    return (
        <group>
            {/* Structural mass: any seam between facade planes reads as shadow. */}
            <mesh castShadow receiveShadow>
                <boxGeometry args={[sx, sy, sz]} />
                <meshStandardMaterial color="#3f434a" roughness={0.9} />
            </mesh>

            {/* Facades, nudged just outside the mass. */}
            <mesh position={[0, 0, hz + 0.02]} receiveShadow>
                <planeGeometry args={[sx, sy]} />
                <meshStandardMaterial map={facadeWide} roughness={0.35} />
            </mesh>
            <mesh position={[0, 0, -hz - 0.02]} rotation={[0, Math.PI, 0]} receiveShadow>
                <planeGeometry args={[sx, sy]} />
                <meshStandardMaterial map={facadeWide} roughness={0.35} />
            </mesh>
            <mesh
                position={[hx + 0.02, 0, 0]}
                rotation={[0, Math.PI / 2, 0]}
                receiveShadow
            >
                <planeGeometry args={[sz, sy]} />
                <meshStandardMaterial map={facadeDeep} roughness={0.35} />
            </mesh>
            <mesh
                position={[-hx - 0.02, 0, 0]}
                rotation={[0, -Math.PI / 2, 0]}
                receiveShadow
            >
                <planeGeometry args={[sz, sy]} />
                <meshStandardMaterial map={facadeDeep} roughness={0.35} />
            </mesh>

            {/* Roof deck. */}
            <mesh
                position={[0, hy + 0.02, 0]}
                rotation={[-Math.PI / 2, 0, 0]}
                receiveShadow
            >
                <planeGeometry args={[sx, sz]} />
                <meshStandardMaterial map={roofMap} roughness={0.95} />
            </mesh>

            {/* Parapet wall around the roof edge. */}
            {(
                [
                    [[0, hy + 0.2, hz], [sx, 0.42, 0.16]],
                    [[0, hy + 0.2, -hz], [sx, 0.42, 0.16]],
                    [[hx, hy + 0.2, 0], [0.16, 0.42, sz]],
                    [[-hx, hy + 0.2, 0], [0.16, 0.42, sz]],
                ] as [[number, number, number], [number, number, number]][]
            ).map(([position, size], i) => (
                <mesh key={i} position={position} castShadow>
                    <boxGeometry args={size} />
                    <meshStandardMaterial color={PARAPET_COLOR} roughness={0.85} />
                </mesh>
            ))}

            <RooftopClutter sx={sx} sz={sz} topY={hy + 0.04} seed={seed} />
        </group>
    );
}

/** A wooden cargo crate. */
function CrateVisual({ obstacle }: { obstacle: ObstacleConfig }) {
    const sx = obstacle.sx ?? 1;
    const sy = obstacle.sy ?? 1;
    const sz = obstacle.sz ?? 1;
    const crateMap = useMemo(() => createCrateTexture(), []);

    useEffect(() => () => crateMap.dispose(), [crateMap]);

    return (
        <mesh castShadow receiveShadow>
            <boxGeometry args={[sx, sy, sz]} />
            <meshStandardMaterial map={crateMap} roughness={0.8} />
        </mesh>
    );
}

/** Pre-cast concrete: walls, barriers, low slabs, and cylinders. */
function ConcreteVisual({ obstacle }: { obstacle: ObstacleConfig }) {
    const isCylinder = obstacle.type === 'cylinder';
    const sx = obstacle.sx ?? 1;
    const sy = obstacle.sy ?? 1;
    const sz = obstacle.sz ?? 1;
    const radius = obstacle.radius ?? 0.5;
    const height = obstacle.height ?? sy;

    const concreteMap = useMemo(() => {
        const texture = createConcreteTexture();
        texture.repeat.set(
            Math.max(1, Math.round(Math.max(sx, sz) / 2)),
            Math.max(1, Math.round((isCylinder ? height : sy) / 2)),
        );

        return texture;
    }, [sx, sz, sy, height, isCylinder]);

    useEffect(() => () => concreteMap.dispose(), [concreteMap]);

    return (
        <mesh castShadow receiveShadow>
            {isCylinder ? (
                <cylinderGeometry args={[radius, radius, height, 24]} />
            ) : (
                <boxGeometry args={[sx, sy, sz]} />
            )}
            <meshStandardMaterial map={concreteMap} roughness={0.9} />
        </mesh>
    );
}

/** Hazard-striped pylon for tall, narrow posts. */
function PylonVisual({ obstacle }: { obstacle: ObstacleConfig }) {
    const sx = obstacle.sx ?? 1;
    const sy = obstacle.sy ?? 1;
    const sz = obstacle.sz ?? 1;

    const hazardMap = useMemo(() => {
        const texture = createHazardTexture();
        texture.repeat.set(
            Math.max(1, Math.round((sx + sz) / 2)),
            Math.max(1, Math.round(sy / 1.2)),
        );

        return texture;
    }, [sx, sy, sz]);

    useEffect(() => () => hazardMap.dispose(), [hazardMap]);

    return (
        <mesh castShadow receiveShadow>
            <boxGeometry args={[sx, sy, sz]} />
            <meshStandardMaterial map={hazardMap} roughness={0.75} />
        </mesh>
    );
}

function Obstacle({ obstacle }: { obstacle: ObstacleConfig }) {
    const kind = classifyObstacle(obstacle);

    let visual: ReactNode;

    switch (kind) {
        case 'building':
            visual = (
                <BuildingVisual
                    obstacle={obstacle}
                    seed={obstacleSeed(obstacle)}
                />
            );
            break;
        case 'crate':
            visual = <CrateVisual obstacle={obstacle} />;
            break;
        case 'pylon':
            visual = <PylonVisual obstacle={obstacle} />;
            break;
        default:
            visual = <ConcreteVisual obstacle={obstacle} />;
            break;
    }

    return <ObstacleBody obstacle={obstacle}>{visual}</ObstacleBody>;
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
                <Obstacle key={index} obstacle={obstacle} />
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
