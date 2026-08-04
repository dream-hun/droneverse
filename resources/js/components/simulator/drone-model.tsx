import { useFrame } from '@react-three/fiber';
import { useRef, useState } from 'react';
import {
    BoxGeometry,
    CircleGeometry,
    CylinderGeometry,
    MeshStandardMaterial,
    SphereGeometry,
} from 'three';
import type { Group, PointLight } from 'three';
import {
    BLADE_LENGTH,
    BODY_RADIUS,
    BOOM_BEARINGS,
    BOOM_LENGTH,
    BOOM_ROOT,
    FOOT_CENTER_Y,
    FOOT_RADIUS,
    isFrontBoom,
    isRearBoom,
    LEG_CENTER_Y,
    LEG_LENGTH,
    LEG_RADIAL_OFFSET,
    MOTOR_CAP_Y,
    MOTOR_POD_Y,
    MOTOR_REACH,
    PROP_RADIUS,
    ROTOR_PLANE_Y,
} from '@/lib/simulator/airframe';
import { createFlightVisualState } from '@/lib/simulator/flight-state';
import type { FlightVisualState } from '@/lib/simulator/flight-state';

/**
 * Procedural inspection hexacopter: a hex-prism carbon core under a brushed
 * sensor dome, six tapered booms in the standard hex-X layout, motor pods
 * with two-blade props that blur into discs as they spool up (alternating
 * CW/CCW around the ring, as a real hex must to cancel torque), red LEDs on
 * the front booms and white anti-collision strobes on the rear, a
 * pitch-stabilized camera gimbal, and a downward vision sensor whose cyan
 * lamp is the drone's own light source. The whole airframe tilts with the
 * flight state — leaning into acceleration and banking against drag — while
 * the rigid body itself only yaws.
 *
 * The airframe is sixty-odd meshes, but it is not sixty-odd *things*: the six
 * booms are one shape, the twelve blades are one shape, every landing foot
 * and lamp is the same little bead. Declared inline, each of those meshes
 * would mint its own geometry and its own material — a separate GPU buffer
 * and a separate uniform upload each, on a model that redraws every frame and
 * again for the shadow pass. They are built once here instead and handed to
 * the meshes that share them.
 *
 * Sharing the materials also collapses the animation bookkeeping: the frame
 * loop would otherwise reach twelve blades, six discs and two strobes through
 * arrays of refs only to write the same value into each. There is one
 * material per group of things that always look alike, and the loop writes to
 * it once.
 */

function clamp(value: number, min: number, max: number): number {
    return Math.min(max, Math.max(min, value));
}

/**
 * The one set of geometries and materials the whole airframe draws from.
 *
 * Anything the frame loop animates is here too, so it can be reached
 * directly rather than collected through a ref per mesh: every blade fades
 * together, every disc fades together, and both strobes flash together, so
 * each of those is a single material and not twelve, six and two.
 */
function createAirframe() {
    const geometries = {
        // A hex prism for the core, so the airframe's silhouette agrees with
        // the six booms leaving it.
        core: new CylinderGeometry(BODY_RADIUS, BODY_RADIUS * 0.88, 0.085, 6),
        coreTrim: new CylinderGeometry(
            BODY_RADIUS * 0.99,
            BODY_RADIUS * 0.99,
            0.012,
            6,
        ),
        belly: new CylinderGeometry(
            BODY_RADIUS * 0.8,
            BODY_RADIUS * 0.6,
            0.03,
            6,
        ),
        // Brushed sensor dome: the top half of a sphere, flattened where it
        // is placed so it reads as a cap on the core rather than a ball
        // sitting on it.
        dome: new SphereGeometry(0.088, 24, 16, 0, Math.PI * 2, 0, Math.PI / 2),
        domeCollar: new CylinderGeometry(0.092, 0.092, 0.012, 24),
        battery: new BoxGeometry(0.12, 0.032, 0.1),
        boom: new CylinderGeometry(0.014, 0.021, BOOM_LENGTH, 10),
        boomLamp: new BoxGeometry(0.03, 0.008, 0.075),
        motorPod: new CylinderGeometry(0.036, 0.042, 0.046, 18),
        motorCap: new CylinderGeometry(0.026, 0.026, 0.012, 18),
        propHub: new CylinderGeometry(0.011, 0.011, 0.02, 10),
        blade: new BoxGeometry(BLADE_LENGTH, 0.0035, 0.024),
        blurDisc: new CircleGeometry(PROP_RADIUS, 28),
        leg: new CylinderGeometry(0.009, 0.011, LEG_LENGTH, 8),
        // Landing feet and every lamp on the airframe are the same bead.
        bead: new SphereGeometry(FOOT_RADIUS, 10, 10),
        gimbalYoke: new BoxGeometry(0.055, 0.03, 0.03),
        gimbalBall: new SphereGeometry(0.036, 20, 16),
        gimbalLens: new CylinderGeometry(0.019, 0.019, 0.006, 18),
        sensorPod: new CylinderGeometry(0.03, 0.034, 0.022, 18),
        sensorLens: new CircleGeometry(0.026, 20),
    };

    const materials = {
        carbon: new MeshStandardMaterial({
            color: '#1b1e23',
            metalness: 0.5,
            roughness: 0.42,
        }),
        dome: new MeshStandardMaterial({
            color: '#767e87',
            metalness: 0.6,
            roughness: 0.42,
        }),
        trim: new MeshStandardMaterial({
            color: '#2b3138',
            metalness: 0.6,
            roughness: 0.35,
        }),
        dark: new MeshStandardMaterial({
            color: '#0f1216',
            roughness: 0.72,
        }),
        motor: new MeshStandardMaterial({
            color: '#0d1013',
            metalness: 0.85,
            roughness: 0.28,
        }),
        motorCap: new MeshStandardMaterial({
            color: '#a8b0b8',
            metalness: 0.92,
            roughness: 0.22,
        }),
        gimbalLens: new MeshStandardMaterial({
            color: '#0b1d33',
            metalness: 0.9,
            roughness: 0.1,
        }),
        blade: new MeshStandardMaterial({
            color: '#101318',
            roughness: 0.62,
            transparent: true,
        }),
        blurDisc: new MeshStandardMaterial({
            color: '#1b1f25',
            transparent: true,
            opacity: 0,
            depthWrite: false,
        }),
        // Front booms burn red, as on every camera drone, so the pilot can
        // read the drone's heading from behind it.
        boomLamp: new MeshStandardMaterial({
            color: '#320907',
            emissive: '#ff2d20',
            emissiveIntensity: 0.5,
        }),
        strobe: new MeshStandardMaterial({
            color: '#23262b',
            emissive: '#ffffff',
            emissiveIntensity: 0.05,
        }),
        status: new MeshStandardMaterial({
            color: '#101418',
            emissive: '#f59e0b',
            emissiveIntensity: 0.6,
        }),
        sensorLens: new MeshStandardMaterial({
            color: '#062730',
            emissive: '#22d3ee',
            emissiveIntensity: 2,
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
 * mounted drone. Only ever one hexacopter is on screen — the marketing
 * showcase and the simulator are different pages — so per-instance copies
 * would buy isolation nothing uses, while a single set survives navigating
 * between missions and spares the GPU re-uploading the same buffers each
 * time.
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
    const sensorLampRef = useRef<PointLight>(null);
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

        // Adjacent rotors on a hex turn opposite ways so the yaw torques
        // cancel, which is what the alternating sign here is.
        propRefs.current.forEach((prop, index) => {
            if (prop) {
                const direction = index % 2 === 0 ? 1 : -1;
                prop.rotation.y += flightState.rotorSpeed * dt * direction;
            }
        });

        // The airframe's own materials, not values this render owns: one
        // blade material fades all twelve blades, one strobe flashes both.
        const animated = airframe().materials;

        // Crossfade blades into a translucent disc as the rotors spool up.
        const blur = clamp((flightState.rotorSpeed - 10) / 45, 0, 1);
        animated.blurDisc.opacity = 0.34 * blur;
        animated.blade.opacity = 1 - 0.85 * blur;

        const seconds = clock.elapsedTime;
        animated.boomLamp.emissiveIntensity = flightState.armed ? 2.6 : 0.5;

        // Double-flash anti-collision strobe.
        const strobePhase = seconds % 1.3;
        animated.strobe.emissiveIntensity =
            strobePhase < 0.07 || (strobePhase > 0.14 && strobePhase < 0.21)
                ? 3.2
                : 0.04;

        // The downward vision sensor only looks at the ground it can reach,
        // so its lamp fades out as the drone climbs away from it.
        const proximity = clamp(1 - flightState.altitude / 4, 0.12, 1);
        animated.sensorLens.emissiveIntensity = flightState.armed
            ? 1.4 + 1.4 * proximity
            : 0.6;

        if (sensorLampRef.current) {
            sensorLampRef.current.intensity = flightState.armed
                ? 0.55 * proximity
                : 0.12;
        }

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
            {/* Carbon core, waist trim, and belly plate. The hex prism's flats
                face the booms, so each one leaves the body square-on. */}
            <mesh
                castShadow
                geometry={geometries.core}
                material={materials.carbon}
            />
            <mesh
                position={[0, 0.036, 0]}
                geometry={geometries.coreTrim}
                material={materials.trim}
            />
            <mesh
                position={[0, -0.055, 0]}
                castShadow
                geometry={geometries.belly}
                material={materials.dark}
            />

            {/* Brushed sensor dome */}
            <mesh
                position={[0, 0.046, 0]}
                geometry={geometries.domeCollar}
                material={materials.trim}
            />
            <mesh
                position={[0, 0.05, 0]}
                scale={[1, 0.66, 1]}
                castShadow
                geometry={geometries.dome}
                material={materials.dome}
            />

            {/* Battery slung under the tail of the core */}
            <mesh
                position={[0, -0.078, 0.055]}
                castShadow
                geometry={geometries.battery}
                material={materials.dark}
            />

            {/* Downward vision sensor: the drone's own light source */}
            <group position={[0, -0.076, 0]}>
                <mesh
                    geometry={geometries.sensorPod}
                    material={materials.motor}
                />
                <mesh
                    position={[0, -0.013, 0]}
                    rotation-x={Math.PI / 2}
                    geometry={geometries.sensorLens}
                    material={materials.sensorLens}
                />
                <pointLight
                    ref={sensorLampRef}
                    color="#22d3ee"
                    intensity={0.12}
                    distance={3.2}
                    decay={2}
                />
            </group>

            {/* Stabilized camera gimbal on the nose */}
            <group position={[0, -0.05, -0.145]}>
                <mesh
                    position={[0, 0.028, 0.03]}
                    geometry={geometries.gimbalYoke}
                    material={materials.dark}
                />
                <group ref={gimbalRef}>
                    <mesh
                        geometry={geometries.gimbalBall}
                        material={materials.dark}
                    />
                    <mesh
                        position={[0, 0, -0.031]}
                        rotation-x={Math.PI / 2}
                        geometry={geometries.gimbalLens}
                        material={materials.gimbalLens}
                    />
                </group>
            </group>

            {/* Booms, motor pods, props, legs, lamps */}
            {BOOM_BEARINGS.map((bearing, index) => {
                const front = isFrontBoom(bearing);
                const rear = isRearBoom(bearing);
                const boomCenter = -(BOOM_ROOT + BOOM_LENGTH / 2);

                return (
                    <group key={bearing} rotation-y={-bearing}>
                        <mesh
                            position={[0, 0.012, boomCenter]}
                            rotation-x={Math.PI / 2}
                            castShadow
                            geometry={geometries.boom}
                            material={materials.carbon}
                        />

                        {/* Motor pod */}
                        <mesh
                            position={[0, MOTOR_POD_Y, -MOTOR_REACH]}
                            castShadow
                            geometry={geometries.motorPod}
                            material={materials.motor}
                        />
                        <mesh
                            position={[0, MOTOR_CAP_Y, -MOTOR_REACH]}
                            geometry={geometries.motorCap}
                            material={materials.motorCap}
                        />

                        {/* Propeller: blades + blur disc */}
                        <group
                            position={[0, ROTOR_PLANE_Y, -MOTOR_REACH]}
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
                                    position={[side * 0.074, 0, 0]}
                                    rotation-x={side * 0.14}
                                    geometry={geometries.blade}
                                    material={materials.blade}
                                />
                            ))}
                        </group>
                        <mesh
                            position={[0, ROTOR_PLANE_Y, -MOTOR_REACH]}
                            rotation-x={-Math.PI / 2}
                            geometry={geometries.blurDisc}
                            material={materials.blurDisc}
                        />

                        {/* Boom lamp: red ahead of the beam, strobe behind */}
                        {(front || rear) && (
                            <mesh
                                position={[0, -0.006, -MOTOR_REACH + 0.055]}
                                geometry={geometries.boomLamp}
                                material={
                                    front
                                        ? materials.boomLamp
                                        : materials.strobe
                                }
                            />
                        )}

                        {/* Landing legs hang off the four swept booms, so the
                            beam pair stays clear for the boom lamps. Their
                            feet stop exactly at the rest height the physics
                            model parks the body at. */}
                        {(front || rear) && (
                            <>
                                <mesh
                                    position={[
                                        0,
                                        LEG_CENTER_Y,
                                        -LEG_RADIAL_OFFSET,
                                    ]}
                                    castShadow
                                    geometry={geometries.leg}
                                    material={materials.dark}
                                />
                                <mesh
                                    position={[
                                        0,
                                        FOOT_CENTER_Y,
                                        -LEG_RADIAL_OFFSET,
                                    ]}
                                    geometry={geometries.bead}
                                    material={materials.dark}
                                />
                            </>
                        )}
                    </group>
                );
            })}

            {/* Rear status LED, readable from the pilot's side of the drone */}
            <mesh
                position={[0, 0.02, 0.142]}
                geometry={geometries.bead}
                material={materials.status}
            />
        </group>
    );
}
