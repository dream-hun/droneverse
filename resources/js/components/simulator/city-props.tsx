import { useFrame } from '@react-three/fiber';
import {
    BallCollider,
    CuboidCollider,
    CylinderCollider,
    RigidBody,
} from '@react-three/rapier';
import { useMemo, useRef } from 'react';
import {
    BoxGeometry,
    CylinderGeometry,
    DoubleSide,
    MeshStandardMaterial,
    PlaneGeometry,
    SphereGeometry,
} from 'three';
import type { BufferAttribute, CanvasTexture, Group, Points } from 'three';
import { positionSeed, seededRng } from '@/lib/simulator/math';
import {
    sharedGeometry,
    sharedMaterial,
} from '@/lib/simulator/scene-resources';
import { carwashSignTexture } from '@/lib/simulator/textures';
import type { CarwashConfig, PropConfig } from '@/types/simulator';

/**
 * Street-level city scenery for City Operations missions. Every prop is a
 * fixed rigid body with an honest collider (clipping a parked car counts
 * as a collision) and is reported by `drone.scan()` at the same position
 * it renders here.
 *
 * Nothing here animates a material, so every material and every repeated
 * shape comes from {@see ../../lib/simulator/scene-resources}: one wheel
 * for all thirty-six on a street, one pane of glass, one tyre. Only the
 * spray curtain keeps its own geometry, because it rewrites its vertices
 * every frame.
 */

const CAR_COLORS = [
    '#b93a35',
    '#2e6f9e',
    '#8d9299',
    '#d8b13c',
    '#3c7d52',
    '#dfe3e6',
    '#39434f',
];

const GLASS_COLOR = '#1c2733';
const TIRE_COLOR = '#181a1c';

/** Stable per-prop seed so colors and tree shapes never change frame to frame. */
function propSeed(prop: PropConfig): number {
    return positionSeed(prop.x, prop.z);
}

function boxGeometry(x: number, y: number, z: number) {
    return sharedGeometry(`box:${x}:${y}:${z}`, () => new BoxGeometry(x, y, z));
}

function planeGeometry(width: number, height: number) {
    return sharedGeometry(
        `plane:${width}:${height}`,
        () => new PlaneGeometry(width, height),
    );
}

/** A painted body panel. Vehicles share a palette, so the paint is shared too. */
function paintMaterial(color: string, roughness: number, metalness: number) {
    return sharedMaterial(
        `paint:${color}:${roughness}:${metalness}`,
        () => new MeshStandardMaterial({ color, roughness, metalness }),
    );
}

function glassMaterial() {
    return paintMaterial(GLASS_COLOR, 0.15, 0.55);
}

function Wheel({ position }: { position: [number, number, number] }) {
    return (
        <mesh
            position={position}
            rotation-z={Math.PI / 2}
            castShadow
            geometry={sharedGeometry(
                'wheel',
                () => new CylinderGeometry(0.3, 0.3, 0.22, 16),
            )}
            material={sharedMaterial(
                'tire',
                () =>
                    new MeshStandardMaterial({
                        color: TIRE_COLOR,
                        roughness: 0.9,
                    }),
            )}
        />
    );
}

/** Head- and tail-lamps: the same little block, lit differently. */
function Lamp({
    position,
    kind,
}: {
    position: [number, number, number];
    kind: 'head' | 'tail';
}) {
    return (
        <mesh
            position={position}
            geometry={boxGeometry(0.3, 0.14, 0.04)}
            material={sharedMaterial(`lamp:${kind}`, () =>
                kind === 'head'
                    ? new MeshStandardMaterial({
                          color: '#f5f2d4',
                          emissive: '#fff7c2',
                          emissiveIntensity: 0.5,
                      })
                    : new MeshStandardMaterial({
                          color: '#5c1310',
                          emissive: '#e0362c',
                          emissiveIntensity: 0.6,
                      }),
            )}
        />
    );
}

function CarVisual({ color }: { color: string }) {
    return (
        <group>
            {/* Lower body shell. */}
            <mesh
                position={[0, 0.62, 0]}
                castShadow
                receiveShadow
                geometry={boxGeometry(1.76, 0.52, 4)}
                material={paintMaterial(color, 0.35, 0.25)}
            />
            {/* Glass cabin. */}
            <mesh
                position={[0, 1.1, 0.18]}
                castShadow
                geometry={boxGeometry(1.58, 0.5, 2.05)}
                material={glassMaterial()}
            />
            {[-0.62, 0.62].map((side) => (
                <group key={side}>
                    <Wheel position={[side * 1.42, 0.3, -1.28]} />
                    <Wheel position={[side * 1.42, 0.3, 1.28]} />
                </group>
            ))}
            {/* Headlights and taillights. */}
            {[-0.55, 0.55].map((side) => (
                <group key={side}>
                    <Lamp position={[side, 0.66, -2.01]} kind="head" />
                    <Lamp position={[side, 0.66, 2.01]} kind="tail" />
                </group>
            ))}
        </group>
    );
}

function VanVisual({ color }: { color: string }) {
    const paint = paintMaterial(color, 0.45, 0.15);

    return (
        <group>
            {/* Cargo body. */}
            <mesh
                position={[0, 1.18, 0.35]}
                castShadow
                receiveShadow
                geometry={boxGeometry(1.95, 1.65, 3.6)}
                material={paint}
            />
            {/* Cab with windshield. */}
            <mesh
                position={[0, 0.95, -1.85]}
                castShadow
                receiveShadow
                geometry={boxGeometry(1.85, 1.15, 1.1)}
                material={paint}
            />
            <mesh
                position={[0, 1.42, -1.98]}
                rotation-x={-0.32}
                castShadow
                geometry={boxGeometry(1.7, 0.62, 0.06)}
                material={glassMaterial()}
            />
            {[-0.62, 0.62].map((side) => (
                <group key={side}>
                    <Wheel position={[side * 1.58, 0.3, -1.55]} />
                    <Wheel position={[side * 1.58, 0.3, 1.35]} />
                </group>
            ))}
        </group>
    );
}

/** Foliage and trunk shapes, shared across every tree on the map. */
function foliage(
    radius: number,
    widthSegments: number,
    heightSegments: number,
) {
    return sharedGeometry(
        `foliage:${radius}:${widthSegments}:${heightSegments}`,
        () => new SphereGeometry(radius, widthSegments, heightSegments),
    );
}

function leafMaterial(color: string) {
    return sharedMaterial(
        `leaf:${color}`,
        () => new MeshStandardMaterial({ color, roughness: 0.9 }),
    );
}

function TreeVisual({ seed }: { seed: number }) {
    // Cheap deterministic variation: two derived unit floats.
    const sway = ((seed % 97) / 97 - 0.5) * 0.35;
    const scale = 0.9 + ((seed % 53) / 53) * 0.3;

    return (
        <group scale={scale} rotation-y={sway * Math.PI}>
            <mesh
                position={[0, 0.85, 0]}
                castShadow
                geometry={sharedGeometry(
                    'trunk',
                    () => new CylinderGeometry(0.12, 0.18, 1.7, 10),
                )}
                material={sharedMaterial(
                    'bark',
                    () =>
                        new MeshStandardMaterial({
                            color: '#6b4a2f',
                            roughness: 0.95,
                        }),
                )}
            />
            <mesh
                position={[0, 2.15, 0]}
                castShadow
                geometry={foliage(0.92, 18, 14)}
                material={leafMaterial('#3f7d3a')}
            />
            <mesh
                position={[0.42 + sway, 2.55, 0.1]}
                castShadow
                geometry={foliage(0.6, 16, 12)}
                material={leafMaterial('#4a8f43')}
            />
            <mesh
                position={[-0.4, 2.45, -0.15]}
                castShadow
                geometry={foliage(0.55, 16, 12)}
                material={leafMaterial('#417f3b')}
            />
        </group>
    );
}

export function CityProp({ prop }: { prop: PropConfig }) {
    const seed = propSeed(prop);
    const color = prop.color ?? CAR_COLORS[seed % CAR_COLORS.length];

    if (prop.kind === 'tree') {
        return (
            <RigidBody
                type="fixed"
                colliders={false}
                position={[prop.x, 0, prop.z]}
                rotation={[0, prop.rotationY ?? 0, 0]}
                userData={{ kind: 'tree' }}
            >
                <CylinderCollider args={[0.85, 0.16]} position={[0, 0.85, 0]} />
                <BallCollider args={[0.85]} position={[0, 2.2, 0]} />
                <TreeVisual seed={seed} />
            </RigidBody>
        );
    }

    const isVan = prop.kind === 'van';

    return (
        <RigidBody
            type="fixed"
            colliders={false}
            position={[prop.x, 0, prop.z]}
            rotation={[0, prop.rotationY ?? 0, 0]}
            userData={{ kind: prop.kind }}
        >
            {isVan ? (
                <CuboidCollider args={[1, 1.05, 2.4]} position={[0, 1.05, 0]} />
            ) : (
                <CuboidCollider
                    args={[0.9, 0.7, 2.05]}
                    position={[0, 0.7, 0]}
                />
            )}
            {isVan ? <VanVisual color={color} /> : <CarVisual color={color} />}
        </RigidBody>
    );
}

const WALL_THICKNESS = 0.35;
const DEFAULT_WASH_WIDTH = 4.5;
const DEFAULT_WASH_HEIGHT = 3.5;
const DEFAULT_WASH_LENGTH = 8;
const DROPLET_COUNT = 90;
const DROPLET_FALL_SPEED = 2.4; // m/s

/**
 * Falling water droplets filling the middle of the tunnel.
 *
 * The only thing in this file that keeps its own geometry: its vertices are
 * rewritten every frame, so it can never be shared.
 */
function SprayCurtain({
    width,
    height,
    length,
}: {
    width: number;
    height: number;
    length: number;
}) {
    const pointsRef = useRef<Points>(null);

    const positions = useMemo(() => {
        const rng = seededRng(0x5eed ^ DROPLET_COUNT);
        const data = new Float32Array(DROPLET_COUNT * 3);

        for (let i = 0; i < DROPLET_COUNT; i++) {
            data[i * 3] = (rng() - 0.5) * width * 0.85;
            data[i * 3 + 1] = rng() * height;
            data[i * 3 + 2] = (rng() - 0.5) * length * 0.55;
        }

        return data;
    }, [width, height, length]);

    useFrame((_, dt) => {
        const points = pointsRef.current;

        if (!points) {
            return;
        }

        const attribute = points.geometry.getAttribute(
            'position',
        ) as BufferAttribute;
        const data = attribute.array as Float32Array;

        for (let i = 0; i < DROPLET_COUNT; i++) {
            let y = data[i * 3 + 1] - DROPLET_FALL_SPEED * dt;

            if (y < 0.05) {
                y = height * (0.85 + Math.random() * 0.15);
            }

            data[i * 3 + 1] = y;
        }

        attribute.needsUpdate = true;
    });

    return (
        <points ref={pointsRef}>
            <bufferGeometry>
                <bufferAttribute
                    attach="attributes-position"
                    args={[positions, 3]}
                />
            </bufferGeometry>
            <pointsMaterial
                color="#9ad7f5"
                size={0.07}
                sizeAttenuation
                transparent
                opacity={0.85}
                depthWrite={false}
            />
        </points>
    );
}

/** A spinning wash brush; the group orients it, the inner mesh spins. */
function Brush({
    position,
    radius,
    length,
    color,
    speed,
    horizontal = false,
}: {
    position: [number, number, number];
    radius: number;
    length: number;
    color: string;
    speed: number;
    horizontal?: boolean;
}) {
    const spinRef = useRef<Group>(null);

    useFrame((_, dt) => {
        const spin = spinRef.current;

        if (spin) {
            spin.rotation.y += speed * dt;
        }
    });

    return (
        <group position={position} rotation-z={horizontal ? Math.PI / 2 : 0}>
            <group ref={spinRef}>
                <mesh
                    castShadow
                    geometry={sharedGeometry(
                        `brush:${radius}:${length}`,
                        () => new CylinderGeometry(radius, radius, length, 18),
                    )}
                    material={sharedMaterial(
                        `brush:${color}`,
                        () =>
                            new MeshStandardMaterial({
                                color,
                                roughness: 0.75,
                            }),
                    )}
                />
                {/* Bristle ridges so the spin actually reads in motion. */}
                {[0, 1, 2, 3].map((i) => (
                    <mesh
                        key={i}
                        rotation-y={(i * Math.PI) / 4}
                        geometry={boxGeometry(
                            radius * 2.35,
                            length * 0.92,
                            0.04,
                        )}
                        material={sharedMaterial(
                            `bristle:${color}`,
                            () =>
                                new MeshStandardMaterial({
                                    color,
                                    roughness: 0.85,
                                    transparent: true,
                                    opacity: 0.55,
                                }),
                        )}
                    />
                ))}
            </group>
        </group>
    );
}

/** Cloth strips hanging at the exit, swaying like a real wash curtain. */
function CurtainFlaps({
    width,
    height,
    z,
}: {
    width: number;
    height: number;
    z: number;
}) {
    const groupRef = useRef<Group>(null);
    const count = 7;
    const flapWidth = (width * 0.9) / count;

    useFrame(({ clock }) => {
        const group = groupRef.current;

        if (!group) {
            return;
        }

        group.children.forEach((flap, index) => {
            flap.rotation.x =
                Math.sin(clock.elapsedTime * 2.1 + index * 1.3) * 0.28;
        });
    });

    return (
        <group ref={groupRef} position={[0, height * 0.94, z]}>
            {Array.from({ length: count }, (_, i) => (
                <group
                    key={i}
                    position={[(i - (count - 1) / 2) * flapWidth * 1.05, 0, 0]}
                >
                    <mesh
                        position={[0, -height * 0.36, 0]}
                        castShadow
                        geometry={boxGeometry(
                            flapWidth * 0.82,
                            height * 0.72,
                            0.03,
                        )}
                        material={sharedMaterial(
                            `flap:${i % 2}`,
                            () =>
                                new MeshStandardMaterial({
                                    color: i % 2 === 0 ? '#1d4ed8' : '#3b82f6',
                                    roughness: 0.85,
                                    side: DoubleSide,
                                }),
                        )}
                    />
                </group>
            ))}
        </group>
    );
}

function signMaterial(texture: CanvasTexture) {
    return sharedMaterial(
        `carwash-sign:${texture.uuid}`,
        () =>
            new MeshStandardMaterial({
                map: texture,
                emissive: '#7dd3fc',
                emissiveIntensity: 0.25,
                emissiveMap: texture,
            }),
    );
}

/**
 * The drive-through drone wash. Walls and roof are solid (clipping them is
 * a collision); two sensor beams just inside the mouths report traversal
 * to the drone's intersection handler — tripping both means a full pass.
 */
export function CarwashStation({ carwash }: { carwash: CarwashConfig }) {
    const width = carwash.width ?? DEFAULT_WASH_WIDTH;
    const height = carwash.height ?? DEFAULT_WASH_HEIGHT;
    const length = carwash.length ?? DEFAULT_WASH_LENGTH;

    const wallX = width / 2 + WALL_THICKNESS / 2;
    const roofY = height + 0.225;

    return (
        <group
            position={[carwash.x, 0, carwash.z]}
            rotation={[0, carwash.rotationY ?? 0, 0]}
        >
            <RigidBody
                type="fixed"
                colliders={false}
                userData={{ kind: 'carwash' }}
            >
                <CuboidCollider
                    args={[WALL_THICKNESS / 2, height / 2, length / 2]}
                    position={[wallX, height / 2, 0]}
                />
                <CuboidCollider
                    args={[WALL_THICKNESS / 2, height / 2, length / 2]}
                    position={[-wallX, height / 2, 0]}
                />
                <CuboidCollider
                    args={[width / 2 + WALL_THICKNESS, 0.225, length / 2]}
                    position={[0, roofY, 0]}
                />

                {/* Side walls. */}
                {[wallX, -wallX].map((x) => (
                    <mesh
                        key={x}
                        position={[x, height / 2, 0]}
                        castShadow
                        receiveShadow
                        geometry={boxGeometry(WALL_THICKNESS, height, length)}
                        material={sharedMaterial(
                            'wash-wall',
                            () =>
                                new MeshStandardMaterial({
                                    color: '#b6c2cc',
                                    roughness: 0.6,
                                    metalness: 0.2,
                                }),
                        )}
                    />
                ))}

                {/* Roof slab. */}
                <mesh
                    position={[0, roofY, 0]}
                    castShadow
                    receiveShadow
                    geometry={boxGeometry(
                        width + WALL_THICKNESS * 2,
                        0.45,
                        length,
                    )}
                    material={sharedMaterial(
                        'wash-roof',
                        () =>
                            new MeshStandardMaterial({
                                color: '#64707c',
                                roughness: 0.8,
                            }),
                    )}
                />
            </RigidBody>

            {/* Wet apron under the tunnel. */}
            <mesh
                position={[0, 0.008, 0]}
                rotation-x={-Math.PI / 2}
                receiveShadow
                geometry={planeGeometry(width + 2.4, length + 2.4)}
                material={sharedMaterial(
                    'wash-apron',
                    () =>
                        new MeshStandardMaterial({
                            color: '#39424a',
                            roughness: 0.35,
                            metalness: 0.1,
                        }),
                )}
            />

            {/* Illuminated signs over both mouths. */}
            {[1, -1].map((side) => (
                <mesh
                    key={side}
                    position={[0, height + 1.05, side * (length / 2 + 0.02)]}
                    rotation-y={side === 1 ? 0 : Math.PI}
                    geometry={planeGeometry(width + 1.4, 1.15)}
                    material={signMaterial(carwashSignTexture())}
                />
            ))}

            {/* Entry pair of vertical brushes, then the overhead roller. */}
            <Brush
                position={[width * 0.32, height * 0.48, length * 0.22]}
                radius={0.42}
                length={height * 0.86}
                color="#2563eb"
                speed={4.2}
            />
            <Brush
                position={[-width * 0.32, height * 0.48, length * 0.22]}
                radius={0.42}
                length={height * 0.86}
                color="#2563eb"
                speed={-4.2}
            />
            <Brush
                position={[0, height * 0.62, -length * 0.05]}
                radius={0.48}
                length={width * 0.86}
                color="#0ea5e9"
                speed={5}
                horizontal
            />

            <CurtainFlaps width={width} height={height} z={-length * 0.33} />
            <SprayCurtain width={width} height={height} length={length} />

            {/* Traversal beams: sensors, so they never push the drone. */}
            <RigidBody
                type="fixed"
                colliders={false}
                userData={{ kind: 'wash-entry' }}
            >
                <CuboidCollider
                    sensor
                    args={[width / 2, height / 2, 0.15]}
                    position={[0, height / 2, length / 2 - 0.5]}
                />
            </RigidBody>
            <RigidBody
                type="fixed"
                colliders={false}
                userData={{ kind: 'wash-exit' }}
            >
                <CuboidCollider
                    sensor
                    args={[width / 2, height / 2, 0.15]}
                    position={[0, height / 2, -length / 2 + 0.5]}
                />
            </RigidBody>
        </group>
    );
}
