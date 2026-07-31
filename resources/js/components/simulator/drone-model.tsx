import { RoundedBox } from '@react-three/drei';
import { useFrame } from '@react-three/fiber';
import { useRef, useState } from 'react';
import {
    BoxGeometry,
    CircleGeometry,
    CylinderGeometry,
    MeshStandardMaterial,
    SphereGeometry,
} from 'three';
import type { Group } from 'three';
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
 *
 * The airframe is forty-nine meshes, but it is not forty-nine *things*: the
 * four arms are one shape, the eight blades are one shape, every landing
 * foot and navigation lamp is the same little sphere. Declared inline, each
 * of those meshes minted its own geometry and its own material — forty-nine
 * of each, every one a separate GPU buffer and a separate uniform upload,
 * on a model that redraws every frame and again for the shadow pass. They
 * are built once here instead and handed to the meshes that share them.
 *
 * Sharing the materials also collapses the animation bookkeeping: the frame
 * loop used to reach eight blades, four discs and two strobes through arrays
 * of refs, only to write the same value into each. There is now one material
 * per group of things that always look alike, and the loop writes to it once.
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

// The X frame is symmetric, so every arm is the same length.
const ARM_LENGTH = Math.hypot(0.24 - ARM_ROOT[0], 0.24 - ARM_ROOT[1]) + 0.06;

function clamp(value: number, min: number, max: number): number {
    return Math.min(max, Math.max(min, value));
}

/**
 * The one set of geometries and materials the whole airframe draws from.
 *
 * Anything the frame loop animates is here too, so it can be reached
 * directly rather than collected through a ref per mesh: every blade fades
 * together, every disc fades together, and both strobes flash together, so
 * each of those is a single material and not eight, four and two.
 */
function createAirframe() {
    const geometries = {
        arm: new BoxGeometry(ARM_LENGTH, 0.026, 0.05),
        noseStripe: new BoxGeometry(0.16, 0.008, 0.05),
        gimbalMount: new BoxGeometry(0.05, 0.035, 0.035),
        gimbalBarrel: new CylinderGeometry(0.023, 0.023, 0.02, 20),
        gimbalLens: new CylinderGeometry(0.017, 0.017, 0.004, 20),
        motorPod: new CylinderGeometry(0.042, 0.046, 0.05, 20),
        motorCap: new CylinderGeometry(0.028, 0.028, 0.012, 20),
        propHub: new CylinderGeometry(0.012, 0.012, 0.022, 12),
        blade: new BoxGeometry(0.185, 0.0035, 0.026),
        blurDisc: new CircleGeometry(0.19, 32),
        leg: new CylinderGeometry(0.011, 0.011, 0.108, 10),
        // Landing feet and every lamp on the airframe are the same bead.
        bead: new SphereGeometry(0.014, 12, 12),
        statusStrip: new BoxGeometry(0.06, 0.012, 0.012),
    };

    const materials = {
        body: new MeshStandardMaterial({
            color: '#282d34',
            metalness: 0.35,
            roughness: 0.5,
        }),
        shell: new MeshStandardMaterial({
            color: '#3d444d',
            metalness: 0.25,
            roughness: 0.55,
        }),
        dark: new MeshStandardMaterial({
            color: '#1c2025',
            roughness: 0.7,
        }),
        motor: new MeshStandardMaterial({
            color: '#15181c',
            metalness: 0.8,
            roughness: 0.3,
        }),
        motorCap: new MeshStandardMaterial({
            color: '#9aa3ad',
            metalness: 0.9,
            roughness: 0.25,
        }),
        noseStripe: new MeshStandardMaterial({
            color: '#38bdf8',
            emissive: '#0ea5e9',
            emissiveIntensity: 0.25,
            roughness: 0.4,
        }),
        gimbalLens: new MeshStandardMaterial({
            color: '#10233f',
            metalness: 0.9,
            roughness: 0.12,
        }),
        blade: new MeshStandardMaterial({
            color: '#181b1f',
            roughness: 0.6,
            transparent: true,
        }),
        blurDisc: new MeshStandardMaterial({
            color: '#22262b',
            transparent: true,
            opacity: 0,
            depthWrite: false,
        }),
        navPort: new MeshStandardMaterial({
            color: '#3a0d0a',
            emissive: '#ff3b30',
            emissiveIntensity: 0.5,
        }),
        navStarboard: new MeshStandardMaterial({
            color: '#0a2f14',
            emissive: '#34c759',
            emissiveIntensity: 0.5,
        }),
        strobe: new MeshStandardMaterial({
            color: '#2b2b23',
            emissive: '#ffffff',
            emissiveIntensity: 0.05,
        }),
        status: new MeshStandardMaterial({
            color: '#101418',
            emissive: '#f59e0b',
            emissiveIntensity: 0.6,
        }),
    };

    return { geometries, materials };
}

type Airframe = ReturnType<typeof createAirframe>;

let shared: Airframe | null = null;

/**
 * The airframe's resources, built on first use and kept for the session.
 *
 * Deliberately one set for the whole application rather than one per
 * mounted drone. Only ever one quadcopter is on screen — the marketing
 * showcase and the simulator are different pages — so per-instance copies
 * would buy isolation nothing uses, while a single set survives navigating
 * between missions and spares the GPU re-uploading the same thirteen
 * buffers each time.
 *
 * Built lazily rather than at import: this module is pulled in by the
 * simulator chunk, and constructing WebGL-bound objects at import time
 * would run them wherever that chunk is merely loaded.
 */
function airframe(): Airframe {
    shared ??= createAirframe();

    return shared;
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
    const { geometries, materials } = airframe();
    const tiltRef = useRef<Group>(null);
    const gimbalRef = useRef<Group>(null);
    const propRefs = useRef<(Group | null)[]>([]);

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

        // The airframe's own materials, not values this render owns: one
        // blade material fades all eight blades, one strobe flashes both.
        const animated = airframe().materials;

        // Crossfade blades into a translucent disc as the rotors spool up.
        const blur = clamp((flightState.rotorSpeed - 10) / 45, 0, 1);
        animated.blurDisc.opacity = 0.34 * blur;
        animated.blade.opacity = 1 - 0.85 * blur;

        const seconds = clock.elapsedTime;
        const armedIntensity = flightState.armed ? 2.4 : 0.5;
        animated.navPort.emissiveIntensity = armedIntensity;
        animated.navStarboard.emissiveIntensity = armedIntensity;

        // Double-flash anti-collision strobe.
        const strobePhase = seconds % 1.3;
        animated.strobe.emissiveIntensity =
            strobePhase < 0.07 || (strobePhase > 0.14 && strobePhase < 0.21)
                ? 3.2
                : 0.04;

        const status = animated.status;

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
    });

    return (
        <group ref={tiltRef}>
            {/* Fuselage */}
            <RoundedBox
                args={[0.3, 0.1, 0.46]}
                radius={0.03}
                castShadow
                material={materials.body}
            />
            <RoundedBox
                args={[0.2, 0.045, 0.28]}
                radius={0.015}
                position={[0, 0.06, 0.02]}
                castShadow
                material={materials.shell}
            />
            {/* Battery pack */}
            <RoundedBox
                args={[0.14, 0.035, 0.12]}
                radius={0.008}
                position={[0, 0.075, 0.13]}
                material={materials.dark}
            />
            {/* Nose accent stripe */}
            <mesh
                position={[0, 0.052, -0.17]}
                geometry={geometries.noseStripe}
                material={materials.noseStripe}
            />

            {/* Stabilized camera gimbal */}
            <group position={[0, -0.045, -0.225]}>
                <mesh
                    position={[0, 0.03, 0.01]}
                    geometry={geometries.gimbalMount}
                    material={materials.dark}
                />
                <group ref={gimbalRef}>
                    <RoundedBox
                        args={[0.075, 0.065, 0.065]}
                        radius={0.012}
                        material={materials.dark}
                    />
                    <mesh
                        position={[0, 0, -0.035]}
                        rotation-x={Math.PI / 2}
                        geometry={geometries.gimbalBarrel}
                        material={materials.motor}
                    />
                    <mesh
                        position={[0, 0, -0.046]}
                        rotation-x={Math.PI / 2}
                        geometry={geometries.gimbalLens}
                        material={materials.gimbalLens}
                    />
                </group>
            </group>

            {/* Arms, motor pods, props, legs, lights */}
            {MOTOR_POSITIONS.map(([x, z], index) => {
                const rootX = Math.sign(x) * ARM_ROOT[0];
                const rootZ = Math.sign(z) * ARM_ROOT[1];
                const angle = Math.atan2(-(z - rootZ), x - rootX);
                const isFront = z < 0;

                return (
                    <group key={index}>
                        <mesh
                            position={[(x + rootX) / 2, 0.02, (z + rootZ) / 2]}
                            rotation-y={angle}
                            castShadow
                            geometry={geometries.arm}
                            material={materials.body}
                        />

                        {/* Motor pod */}
                        <mesh
                            position={[x, 0.045, z]}
                            castShadow
                            geometry={geometries.motorPod}
                            material={materials.motor}
                        />
                        <mesh
                            position={[x, 0.075, z]}
                            geometry={geometries.motorCap}
                            material={materials.motorCap}
                        />

                        {/* Propeller: blades + blur disc */}
                        <group
                            position={[x, 0.09, z]}
                            ref={(prop) => {
                                propRefs.current[index] = prop;
                            }}
                        >
                            <mesh
                                geometry={geometries.propHub}
                                material={materials.motor}
                            />
                            {[1, -1].map((side) => (
                                <mesh
                                    key={side}
                                    position={[side * 0.0975, 0, 0]}
                                    rotation-x={side * 0.14}
                                    geometry={geometries.blade}
                                    material={materials.blade}
                                />
                            ))}
                        </group>
                        <mesh
                            position={[x, 0.09, z]}
                            rotation-x={-Math.PI / 2}
                            geometry={geometries.blurDisc}
                            material={materials.blurDisc}
                        />

                        {/* Landing leg */}
                        <mesh
                            position={[x * 0.84, -0.082, z * 0.84]}
                            castShadow
                            geometry={geometries.leg}
                            material={materials.dark}
                        />
                        <mesh
                            position={[x * 0.84, -0.136, z * 0.84]}
                            geometry={geometries.bead}
                            material={materials.dark}
                        />

                        {/* Navigation and strobe lights */}
                        <mesh
                            position={[x, 0.012, z]}
                            geometry={geometries.bead}
                            material={
                                isFront
                                    ? x < 0
                                        ? materials.navPort
                                        : materials.navStarboard
                                    : materials.strobe
                            }
                        />
                    </group>
                );
            })}

            {/* Rear status LED strip */}
            <mesh
                position={[0, 0.045, 0.225]}
                geometry={geometries.statusStrip}
                material={materials.status}
            />
        </group>
    );
}
