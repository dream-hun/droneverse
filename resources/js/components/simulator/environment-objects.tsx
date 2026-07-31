import { useFrame } from '@react-three/fiber';
import {
    CuboidCollider,
    CylinderCollider,
    RigidBody,
} from '@react-three/rapier';
import { memo, useEffect, useMemo, useRef } from 'react';
import type { ReactNode } from 'react';
import {
    BoxGeometry,
    CircleGeometry,
    CylinderGeometry,
    MeshStandardMaterial,
    PlaneGeometry,
    SphereGeometry,
    TorusGeometry,
} from 'three';
import type { CanvasTexture, Group } from 'three';
import { CarwashStation, CityProp } from '@/components/simulator/city-props';
import { positionSeed, seededRng } from '@/lib/simulator/math';
import { classifyObstacle } from '@/lib/simulator/obstacles';
import {
    releaseSceneResources,
    sharedGeometry,
    sharedMaterial,
} from '@/lib/simulator/scene-resources';
import { releaseTextureCache } from '@/lib/simulator/texture-cache';
import {
    concreteTexture,
    crateTexture,
    facadeTexture,
    fieldTexture,
    grassTexture,
    hazardTexture,
    helipadTexture,
    roofTexture,
    targetPadTexture,
} from '@/lib/simulator/textures';
import type {
    EnvironmentConfig,
    GateConfig,
    ObstacleConfig,
    WaypointConfig,
} from '@/types/simulator';

/** Stable per-obstacle seed so a tower's windows never flicker between frames. */
function obstacleSeed(obstacle: ObstacleConfig): number {
    return positionSeed(obstacle.x, obstacle.z, obstacle.sy ?? 1);
}

// --- Shared shapes and surfaces -------------------------------------------
// Keyed on exactly what makes two of them differ, so a field of identical
// crates is one box and one surface, not twenty of each.

function boxGeometry(x: number, y: number, z: number) {
    return sharedGeometry(`box:${x}:${y}:${z}`, () => new BoxGeometry(x, y, z));
}

function planeGeometry(width: number, height: number) {
    return sharedGeometry(
        `plane:${width}:${height}`,
        () => new PlaneGeometry(width, height),
    );
}

function cylinderGeometry(
    radius: number,
    height: number,
    segments: number,
    topRadius: number = radius,
) {
    return sharedGeometry(
        `cyl:${topRadius}:${radius}:${height}:${segments}`,
        () => new CylinderGeometry(topRadius, radius, height, segments),
    );
}

function circleGeometry(radius: number, segments: number) {
    return sharedGeometry(
        `circle:${radius}:${segments}`,
        () => new CircleGeometry(radius, segments),
    );
}

function plainMaterial(color: string, roughness: number, metalness?: number) {
    return sharedMaterial(
        `plain:${color}:${roughness}:${metalness ?? '-'}`,
        () =>
            new MeshStandardMaterial(
                metalness === undefined
                    ? { color, roughness }
                    : { color, roughness, metalness },
            ),
    );
}

/** A surface carrying one of the procedural textures. */
function texturedMaterial(
    texture: CanvasTexture,
    roughness: number,
    extra?: { transparent?: boolean; opacity?: number; color?: string },
) {
    const key = `tex:${texture.uuid}:${roughness}:${extra?.transparent ? 1 : 0}:${extra?.opacity ?? 1}:${extra?.color ?? '-'}`;

    return sharedMaterial(
        key,
        () =>
            new MeshStandardMaterial({
                map: texture,
                roughness,
                ...extra,
            }),
    );
}

function emissiveMaterial(
    color: string,
    emissive: string,
    emissiveIntensity: number,
    extra?: { transparent?: boolean; opacity?: number },
) {
    const key = `emissive:${color}:${emissive}:${emissiveIntensity}:${extra?.opacity ?? 1}`;

    return sharedMaterial(
        key,
        () =>
            new MeshStandardMaterial({
                color,
                emissive,
                emissiveIntensity,
                ...extra,
            }),
    );
}

const GATE_POST_RADIUS = 0.06;
const GATE_BAND_HEIGHTS = [0.35, 0.7];

function GateFrame({ gate }: { gate: GateConfig }) {
    return (
        <group position={[gate.x, 0, gate.z]} rotation={[0, gate.rotationY, 0]}>
            {[-1, 1].map((side) => (
                <group key={side} position={[(side * gate.width) / 2, 0, 0]}>
                    <mesh
                        position={[0, gate.height / 2, 0]}
                        castShadow
                        geometry={cylinderGeometry(
                            GATE_POST_RADIUS,
                            gate.height,
                            12,
                        )}
                        material={plainMaterial('#f0b429', 0.55)}
                    />
                    {GATE_BAND_HEIGHTS.map((ratio) => (
                        <mesh
                            key={ratio}
                            position={[0, gate.height * ratio, 0]}
                            geometry={cylinderGeometry(0.075, 0.14, 12)}
                            material={emissiveMaterial(
                                '#7c5410',
                                '#fbbf24',
                                1.1,
                            )}
                        />
                    ))}
                    <mesh
                        position={[0, 0.015, 0]}
                        receiveShadow
                        geometry={boxGeometry(0.45, 0.03, 0.45)}
                        material={plainMaterial('#272c31', 0.8)}
                    />
                </group>
            ))}
            <mesh
                position={[0, gate.height, 0]}
                rotation-z={Math.PI / 2}
                castShadow
                geometry={cylinderGeometry(
                    GATE_POST_RADIUS,
                    gate.width + 0.3,
                    12,
                )}
                material={plainMaterial('#f0b429', 0.55)}
            />
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
                    geometry={boxGeometry(...item.size)}
                    material={plainMaterial(item.color, 0.7)}
                />
            ))}
            {/* Antenna mast with a warning light. */}
            <mesh
                position={[sx * 0.28, topY + 1.1, -sz * 0.28]}
                castShadow
                geometry={cylinderGeometry(0.04, 2.2, 8)}
                material={plainMaterial('#2c3036', 1, 0.6)}
            />
            <mesh
                position={[sx * 0.28, topY + 2.25, -sz * 0.28]}
                geometry={sharedGeometry(
                    'mast-lamp',
                    () => new SphereGeometry(0.09, 10, 10),
                )}
                material={emissiveMaterial('#4a0f0c', '#ff2a1f', 1.6)}
            />
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
    const facadeWide = texturedMaterial(facadeTexture(sx, sy, seed), 0.35);
    const facadeDeep = texturedMaterial(
        facadeTexture(sz, sy, seed ^ 0x1234),
        0.35,
    );
    const roofSurface = texturedMaterial(roofTexture(seed), 0.95);
    const parapet = plainMaterial(PARAPET_COLOR, 0.85);

    return (
        <group>
            {/* Structural mass: any seam between facade planes reads as shadow. */}
            <mesh
                castShadow
                receiveShadow
                geometry={boxGeometry(sx, sy, sz)}
                material={plainMaterial('#3f434a', 0.9)}
            />

            {/* Facades, nudged just outside the mass. */}
            <mesh
                position={[0, 0, hz + 0.02]}
                receiveShadow
                geometry={planeGeometry(sx, sy)}
                material={facadeWide}
            />
            <mesh
                position={[0, 0, -hz - 0.02]}
                rotation={[0, Math.PI, 0]}
                receiveShadow
                geometry={planeGeometry(sx, sy)}
                material={facadeWide}
            />
            <mesh
                position={[hx + 0.02, 0, 0]}
                rotation={[0, Math.PI / 2, 0]}
                receiveShadow
                geometry={planeGeometry(sz, sy)}
                material={facadeDeep}
            />
            <mesh
                position={[-hx - 0.02, 0, 0]}
                rotation={[0, -Math.PI / 2, 0]}
                receiveShadow
                geometry={planeGeometry(sz, sy)}
                material={facadeDeep}
            />

            {/* Roof deck. */}
            <mesh
                position={[0, hy + 0.02, 0]}
                rotation={[-Math.PI / 2, 0, 0]}
                receiveShadow
                geometry={planeGeometry(sx, sz)}
                material={roofSurface}
            />

            {/* Parapet wall around the roof edge. */}
            {(
                [
                    [
                        [0, hy + 0.2, hz],
                        [sx, 0.42, 0.16],
                    ],
                    [
                        [0, hy + 0.2, -hz],
                        [sx, 0.42, 0.16],
                    ],
                    [
                        [hx, hy + 0.2, 0],
                        [0.16, 0.42, sz],
                    ],
                    [
                        [-hx, hy + 0.2, 0],
                        [0.16, 0.42, sz],
                    ],
                ] as [[number, number, number], [number, number, number]][]
            ).map(([position, size], i) => (
                <mesh
                    key={i}
                    position={position}
                    castShadow
                    geometry={boxGeometry(...size)}
                    material={parapet}
                />
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

    return (
        <mesh
            castShadow
            receiveShadow
            geometry={boxGeometry(sx, sy, sz)}
            material={texturedMaterial(crateTexture(), 0.8)}
        />
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

    const concreteMap = concreteTexture(
        Math.max(1, Math.round(Math.max(sx, sz) / 2)),
        Math.max(1, Math.round((isCylinder ? height : sy) / 2)),
    );

    return (
        <mesh
            castShadow
            receiveShadow
            geometry={
                isCylinder
                    ? cylinderGeometry(radius, height, 24)
                    : boxGeometry(sx, sy, sz)
            }
            material={texturedMaterial(concreteMap, 0.9)}
        />
    );
}

/** Hazard-striped pylon for tall, narrow posts. */
function PylonVisual({ obstacle }: { obstacle: ObstacleConfig }) {
    const sx = obstacle.sx ?? 1;
    const sy = obstacle.sy ?? 1;
    const sz = obstacle.sz ?? 1;

    const hazardMap = hazardTexture(
        Math.max(1, Math.round((sx + sz) / 2)),
        Math.max(1, Math.round(sy / 1.2)),
    );

    return (
        <mesh
            castShadow
            receiveShadow
            geometry={boxGeometry(sx, sy, sz)}
            material={texturedMaterial(hazardMap, 0.75)}
        />
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
            <mesh
                rotation-x={Math.PI / 2}
                geometry={sharedGeometry(
                    `beacon-ring:${waypoint.radius}`,
                    () => new TorusGeometry(waypoint.radius, 0.035, 12, 48),
                )}
                material={emissiveMaterial('#22d3ee', '#22d3ee', 1.1, {
                    transparent: true,
                    opacity: 0.55,
                })}
            />
            <mesh
                geometry={sharedGeometry(
                    'beacon-core',
                    () => new SphereGeometry(0.12, 16, 16),
                )}
                material={emissiveMaterial('#22d3ee', '#22d3ee', 2, {
                    transparent: true,
                    opacity: 0.85,
                })}
            />
        </group>
    );
}

/**
 * The scene is fixed for the whole mission, so it is built once.
 *
 * A mission's environment never changes while it is being flown — the
 * obstacles, gates, beacons and props are authored data — but this subtree
 * is the largest in the application, and anything that re-renders the
 * viewport around it (switching camera mode, a keystroke reaching the page
 * above) would otherwise walk every one of those meshes again. The
 * `environment` prop arrives by reference from the Inertia page props, so
 * the compare below holds until the pilot navigates to another mission.
 */
function EnvironmentObjectsComponent({
    environment,
}: {
    environment: EnvironmentConfig;
}) {
    const span = Math.max(environment.bounds.width, environment.bounds.depth);

    const textures = {
        field: fieldTexture(environment.bounds.width, environment.bounds.depth),
        grass: grassTexture(span * 2.5),
        helipad: helipadTexture(),
        target: targetPadTexture(),
    };

    // The scene owns every procedural resource in it, including the ones its
    // children asked for, so both caches are emptied here and nowhere else.
    useEffect(
        () => () => {
            releaseTextureCache();
            releaseSceneResources();
        },
        [],
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
                    geometry={planeGeometry(span * 8, span * 8)}
                    material={texturedMaterial(textures.grass, 1, {
                        color: '#9aa78a',
                    })}
                />
                <mesh
                    receiveShadow
                    rotation-x={-Math.PI / 2}
                    geometry={planeGeometry(
                        environment.bounds.width,
                        environment.bounds.depth,
                    )}
                    material={texturedMaterial(textures.field, 0.95)}
                />
            </RigidBody>

            {/* Launch helipad */}
            <mesh
                position={[environment.start.x, 0.012, environment.start.z]}
                rotation-x={-Math.PI / 2}
                receiveShadow
                geometry={circleGeometry(1.15, 48)}
                material={texturedMaterial(textures.helipad, 0.9, {
                    transparent: true,
                })}
            />

            {/* Goal landing target */}
            <mesh
                position={[environment.goal.x, 0.011, environment.goal.z]}
                rotation-x={-Math.PI / 2}
                receiveShadow
                geometry={circleGeometry(environment.goal.radius, 48)}
                material={texturedMaterial(textures.target, 0.9, {
                    transparent: true,
                    opacity: 0.92,
                })}
            />

            {environment.obstacles.map((obstacle, index) => (
                <Obstacle key={index} obstacle={obstacle} />
            ))}

            {environment.gates.map((gate, index) => (
                <GateFrame key={index} gate={gate} />
            ))}

            {environment.waypoints.map((waypoint, index) => (
                <WaypointBeacon key={index} waypoint={waypoint} index={index} />
            ))}

            {(environment.props ?? []).map((prop, index) => (
                <CityProp key={index} prop={prop} />
            ))}

            {environment.carwash && (
                <CarwashStation carwash={environment.carwash} />
            )}
        </>
    );
}

export const EnvironmentObjects = memo(EnvironmentObjectsComponent);
