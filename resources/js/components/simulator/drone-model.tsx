import { RoundedBox } from '@react-three/drei';
import { useFrame } from '@react-three/fiber';
import { useRef, useState } from 'react';
import type { Group, MeshStandardMaterial } from 'three';
import { createFlightVisualState } from '@/lib/simulator/flight-state';
import type { FlightVisualState } from '@/lib/simulator/flight-state';

/**
 * Procedural prosumer quadcopter: fuselage with top shell and battery,
 * X-frame arms with motor pods, two-blade props that blur into discs as
 * they spool up (CW/CCW pairs like a real quad), a pitch-stabilized camera
 * gimbal, landing legs, and aviation lighting (port red, starboard green,
 * rear strobes). The whole airframe tilts with the flight state — leaning
 * into acceleration and banking against drag — while the rigid body itself
 * only yaws.
 */

// Motor pod centers, mirrored into an X configuration.
const MOTOR_POSITIONS: [number, number][] = [
    [0.24, -0.24],
    [-0.24, -0.24],
    [0.24, 0.24],
    [-0.24, 0.24],
];

// Arms run from the fuselage corners out to the pods.
const ARM_ROOT: [number, number] = [0.11, 0.145];

const BODY = { color: '#282d34', metalness: 0.35, roughness: 0.5 };
const DARK = { color: '#1c2025', roughness: 0.7 };
const MOTOR = { color: '#15181c', metalness: 0.8, roughness: 0.3 };

function clamp(value: number, min: number, max: number): number {
    return Math.min(max, Math.max(min, value));
}

export function DroneModel({
    flightState: flightStateProp,
}: {
    /** Omitted in showcase renders (marketing page): idles with slow props. */
    flightState?: FlightVisualState;
}) {
    const [showcaseState] = useState(() => {
        const state = createFlightVisualState();
        state.rotorSpeed = 14;

        return state;
    });
    const flightState = flightStateProp ?? showcaseState;
    const tiltRef = useRef<Group>(null);
    const gimbalRef = useRef<Group>(null);
    const propRefs = useRef<(Group | null)[]>([]);
    const bladeMaterialRefs = useRef<(MeshStandardMaterial | null)[]>([]);
    const discMaterialRefs = useRef<(MeshStandardMaterial | null)[]>([]);
    const navMaterialRefs = useRef<(MeshStandardMaterial | null)[]>([]);
    const strobeMaterialRefs = useRef<(MeshStandardMaterial | null)[]>([]);
    const statusMaterialRef = useRef<MeshStandardMaterial | null>(null);

    useFrame(({ clock }, dt) => {
        const tilt = tiltRef.current;

        if (tilt) {
            tilt.rotation.x = flightState.pitch;
            tilt.rotation.z = flightState.roll;
        }

        // The gimbal counter-rotates to keep the camera level, exactly like
        // a real stabilized gimbal.
        if (gimbalRef.current) {
            gimbalRef.current.rotation.x = clamp(
                -flightState.pitch,
                -0.35,
                0.35,
            );
        }

        propRefs.current.forEach((prop, index) => {
            if (!prop) {
                return;
            }

            const [x, z] = MOTOR_POSITIONS[index];
            const direction = x * z > 0 ? 1 : -1;
            prop.rotation.y += flightState.rotorSpeed * dt * direction;
        });

        // Crossfade blades into a translucent disc as the rotors spool up.
        const blur = clamp((flightState.rotorSpeed - 10) / 45, 0, 1);
        discMaterialRefs.current.forEach((material) => {
            if (material) {
                material.opacity = 0.34 * blur;
            }
        });
        bladeMaterialRefs.current.forEach((material) => {
            if (material) {
                material.opacity = 1 - 0.85 * blur;
            }
        });

        const seconds = clock.elapsedTime;
        const armedIntensity = flightState.armed ? 2.4 : 0.5;
        navMaterialRefs.current.forEach((material) => {
            if (material) {
                material.emissiveIntensity = armedIntensity;
            }
        });

        // Double-flash anti-collision strobe.
        const strobePhase = seconds % 1.3;
        const strobeIntensity =
            strobePhase < 0.07 || (strobePhase > 0.14 && strobePhase < 0.21)
                ? 3.2
                : 0.04;
        strobeMaterialRefs.current.forEach((material) => {
            if (material) {
                material.emissiveIntensity = strobeIntensity;
            }
        });

        const status = statusMaterialRef.current;

        if (status) {
            if (flightState.batteryPct < 25) {
                status.emissive.set('#ef4444');
                status.emissiveIntensity =
                    Math.sin(seconds * Math.PI * 4) > 0 ? 2.2 : 0.1;
            } else if (flightState.armed) {
                status.emissive.set('#22c55e');
                status.emissiveIntensity = 1.8;
            } else {
                status.emissive.set('#f59e0b');
                status.emissiveIntensity = 0.5 + 0.45 * Math.sin(seconds * 2.2);
            }
        }
    });

    return (
        <group ref={tiltRef}>
            {/* Fuselage */}
            <RoundedBox args={[0.3, 0.1, 0.46]} radius={0.03} castShadow>
                <meshStandardMaterial {...BODY} />
            </RoundedBox>
            <RoundedBox
                args={[0.2, 0.045, 0.28]}
                radius={0.015}
                position={[0, 0.06, 0.02]}
                castShadow
            >
                <meshStandardMaterial
                    color="#3d444d"
                    metalness={0.25}
                    roughness={0.55}
                />
            </RoundedBox>
            {/* Battery pack */}
            <RoundedBox
                args={[0.14, 0.035, 0.12]}
                radius={0.008}
                position={[0, 0.075, 0.13]}
            >
                <meshStandardMaterial {...DARK} />
            </RoundedBox>
            {/* Nose accent stripe */}
            <mesh position={[0, 0.052, -0.17]}>
                <boxGeometry args={[0.16, 0.008, 0.05]} />
                <meshStandardMaterial
                    color="#38bdf8"
                    emissive="#0ea5e9"
                    emissiveIntensity={0.25}
                    roughness={0.4}
                />
            </mesh>

            {/* Stabilized camera gimbal */}
            <group position={[0, -0.045, -0.225]}>
                <mesh position={[0, 0.03, 0.01]}>
                    <boxGeometry args={[0.05, 0.035, 0.035]} />
                    <meshStandardMaterial {...DARK} />
                </mesh>
                <group ref={gimbalRef}>
                    <RoundedBox args={[0.075, 0.065, 0.065]} radius={0.012}>
                        <meshStandardMaterial {...DARK} />
                    </RoundedBox>
                    <mesh position={[0, 0, -0.035]} rotation-x={Math.PI / 2}>
                        <cylinderGeometry args={[0.023, 0.023, 0.02, 20]} />
                        <meshStandardMaterial {...MOTOR} />
                    </mesh>
                    <mesh position={[0, 0, -0.046]} rotation-x={Math.PI / 2}>
                        <cylinderGeometry args={[0.017, 0.017, 0.004, 20]} />
                        <meshStandardMaterial
                            color="#10233f"
                            metalness={0.9}
                            roughness={0.12}
                        />
                    </mesh>
                </group>
            </group>

            {/* Arms, motor pods, props, legs, lights */}
            {MOTOR_POSITIONS.map(([x, z], index) => {
                const rootX = Math.sign(x) * ARM_ROOT[0];
                const rootZ = Math.sign(z) * ARM_ROOT[1];
                const dx = x - rootX;
                const dz = z - rootZ;
                const length = Math.hypot(dx, dz) + 0.06;
                const angle = Math.atan2(-dz, dx);
                const isFront = z < 0;

                return (
                    <group key={index}>
                        <mesh
                            position={[(x + rootX) / 2, 0.02, (z + rootZ) / 2]}
                            rotation-y={angle}
                            castShadow
                        >
                            <boxGeometry args={[length, 0.026, 0.05]} />
                            <meshStandardMaterial {...BODY} />
                        </mesh>

                        {/* Motor pod */}
                        <mesh position={[x, 0.045, z]} castShadow>
                            <cylinderGeometry args={[0.042, 0.046, 0.05, 20]} />
                            <meshStandardMaterial {...MOTOR} />
                        </mesh>
                        <mesh position={[x, 0.075, z]}>
                            <cylinderGeometry
                                args={[0.028, 0.028, 0.012, 20]}
                            />
                            <meshStandardMaterial
                                color="#9aa3ad"
                                metalness={0.9}
                                roughness={0.25}
                            />
                        </mesh>

                        {/* Propeller: blades + blur disc */}
                        <group
                            position={[x, 0.09, z]}
                            ref={(prop) => {
                                propRefs.current[index] = prop;
                            }}
                        >
                            <mesh>
                                <cylinderGeometry
                                    args={[0.012, 0.012, 0.022, 12]}
                                />
                                <meshStandardMaterial {...MOTOR} />
                            </mesh>
                            {[1, -1].map((side) => (
                                <mesh
                                    key={side}
                                    position={[side * 0.0975, 0, 0]}
                                    rotation-x={side * 0.14}
                                >
                                    <boxGeometry
                                        args={[0.185, 0.0035, 0.026]}
                                    />
                                    <meshStandardMaterial
                                        ref={(material) => {
                                            bladeMaterialRefs.current[
                                                index * 2 + (side === 1 ? 0 : 1)
                                            ] = material;
                                        }}
                                        color="#181b1f"
                                        roughness={0.6}
                                        transparent
                                    />
                                </mesh>
                            ))}
                        </group>
                        <mesh position={[x, 0.09, z]} rotation-x={-Math.PI / 2}>
                            <circleGeometry args={[0.19, 32]} />
                            <meshStandardMaterial
                                ref={(material) => {
                                    discMaterialRefs.current[index] = material;
                                }}
                                color="#22262b"
                                transparent
                                opacity={0}
                                depthWrite={false}
                            />
                        </mesh>

                        {/* Landing leg */}
                        <mesh
                            position={[x * 0.84, -0.082, z * 0.84]}
                            castShadow
                        >
                            <cylinderGeometry
                                args={[0.011, 0.011, 0.108, 10]}
                            />
                            <meshStandardMaterial {...DARK} />
                        </mesh>
                        <mesh position={[x * 0.84, -0.136, z * 0.84]}>
                            <sphereGeometry args={[0.014, 12, 12]} />
                            <meshStandardMaterial {...DARK} />
                        </mesh>

                        {/* Navigation and strobe lights */}
                        <mesh position={[x, 0.012, z]}>
                            <sphereGeometry args={[0.014, 12, 12]} />
                            {isFront ? (
                                <meshStandardMaterial
                                    ref={(material) => {
                                        navMaterialRefs.current[x < 0 ? 0 : 1] =
                                            material;
                                    }}
                                    color={x < 0 ? '#3a0d0a' : '#0a2f14'}
                                    emissive={x < 0 ? '#ff3b30' : '#34c759'}
                                    emissiveIntensity={0.5}
                                />
                            ) : (
                                <meshStandardMaterial
                                    ref={(material) => {
                                        strobeMaterialRefs.current[
                                            x < 0 ? 0 : 1
                                        ] = material;
                                    }}
                                    color="#2b2b23"
                                    emissive="#ffffff"
                                    emissiveIntensity={0.05}
                                />
                            )}
                        </mesh>
                    </group>
                );
            })}

            {/* Rear status LED strip */}
            <mesh position={[0, 0.045, 0.225]}>
                <boxGeometry args={[0.06, 0.012, 0.012]} />
                <meshStandardMaterial
                    ref={statusMaterialRef}
                    color="#101418"
                    emissive="#f59e0b"
                    emissiveIntensity={0.6}
                />
            </mesh>
        </group>
    );
}
