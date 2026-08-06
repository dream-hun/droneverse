import { useFrame } from '@react-three/fiber';
import { useMemo, useRef, useState } from 'react';
import {
    BoxGeometry,
    CircleGeometry,
    CylinderGeometry,
    MeshStandardMaterial,
    SphereGeometry,
} from 'three';
import type { Group, PointLight } from 'three';
import {
    airframeGeometry,
    isFrontBoom,
    isRearBoom,
    REFERENCE_BODY_RADIUS,
} from '@/lib/simulator/airframe';
import type { AirframeGeometry } from '@/lib/simulator/airframe';
import { createFlightVisualState } from '@/lib/simulator/flight-state';
import type { FlightVisualState } from '@/lib/simulator/flight-state';
import type { DroneModelSummary } from '@/types/drone';

/**
 * Procedural multirotor: a prism carbon core under a brushed sensor dome,
 * evenly spaced tapered booms, motor pods with two-blade props that blur into
 * discs as they spool up (alternating CW/CCW around the ring, as a real
 * multirotor must to cancel torque), red LEDs on the front booms and white
 * anti-collision strobes on the rear, a pitch-stabilized camera gimbal, and a
 * downward vision sensor whose cyan lamp is the drone's own light source. The
 * whole airframe tilts with the flight state — leaning into acceleration and
 * banking against drag — while the rigid body itself only yaws.
 *
 * One component draws the whole fleet. Every dimension comes from the chosen
 * drone's `AirframeGeometry`, so a Cadet is a small four-boom quad and a
 * Freighter is a wide eight-boom lifter out of the same mesh tree — which is
 * the only way the geometry the physics is checked against and the geometry
 * the pilot sees can be guaranteed to be the same numbers.
 *
 * The airframe is sixty-odd meshes, but it is not sixty-odd *things*: the
 * booms are one shape, the blades are one shape, every landing foot and lamp
 * is the same little bead. Declared inline, each of those meshes would mint
 * its own geometry and its own material — a separate GPU buffer and a
 * separate uniform upload each, on a model that redraws every frame and again
 * for the shadow pass. They are built once per airframe here instead and
 * handed to the meshes that share them.
 *
 * Sharing the materials also collapses the animation bookkeeping: the frame
 * loop would otherwise reach every blade, disc and strobe through arrays of
 * refs only to write the same value into each. There is one material per
 * group of things that always look alike, and the loop writes to it once.
 */

function clamp(value: number, min: number, max: number): number {
    return Math.min(max, Math.max(min, value));
}

/**
 * The one set of geometries and materials one airframe draws from.
 *
 * Anything the frame loop animates is here too, so it can be reached
 * directly rather than collected through a ref per mesh: every blade fades
 * together, every disc fades together, and both strobes flash together, so
 * each of those is a single material and not one per mesh.
 */
function createAirframeResources(geometry: AirframeGeometry) {
    // The fixed detail dimensions below were authored against the stock
    // hexacopter's core, so everything absolute is scaled by how this
    // airframe's core compares with it.
    const scale = geometry.bodyRadius / REFERENCE_BODY_RADIUS;
    const radius = geometry.bodyRadius;

    const geometries = {
        // A prism for the core with one flat per boom, so the airframe's
        // silhouette agrees with the booms leaving it.
        core: new CylinderGeometry(
            radius,
            radius * 0.88,
            0.085 * scale,
            geometry.rotors,
        ),
        coreTrim: new CylinderGeometry(
            radius * 0.99,
            radius * 0.99,
            0.012 * scale,
            geometry.rotors,
        ),
        belly: new CylinderGeometry(
            radius * 0.8,
            radius * 0.6,
            0.03 * scale,
            geometry.rotors,
        ),
        // Brushed sensor dome: the top half of a sphere, flattened where it
        // is placed so it reads as a cap on the core rather than a ball
        // sitting on it.
        dome: new SphereGeometry(
            0.088 * scale,
            24,
            16,
            0,
            Math.PI * 2,
            0,
            Math.PI / 2,
        ),
        domeCollar: new CylinderGeometry(
            0.092 * scale,
            0.092 * scale,
            0.012 * scale,
            24,
        ),
        battery: new BoxGeometry(0.12 * scale, 0.032 * scale, 0.1 * scale),
        boom: new CylinderGeometry(
            0.014 * scale,
            0.021 * scale,
            geometry.boomLength,
            10,
        ),
        boomLamp: new BoxGeometry(0.03 * scale, 0.008 * scale, 0.075 * scale),
        motorPod: new CylinderGeometry(
            0.036 * scale,
            0.042 * scale,
            0.046 * scale,
            18,
        ),
        motorCap: new CylinderGeometry(
            0.026 * scale,
            0.026 * scale,
            0.012 * scale,
            18,
        ),
        propHub: new CylinderGeometry(
            0.011 * scale,
            0.011 * scale,
            0.02 * scale,
            10,
        ),
        blade: new BoxGeometry(
            geometry.bladeLength,
            0.0035 * scale,
            0.024 * scale,
        ),
        blurDisc: new CircleGeometry(geometry.propRadius, 28),
        leg: new CylinderGeometry(
            0.009 * scale,
            0.011 * scale,
            geometry.legLength,
            8,
        ),
        // Landing feet and every lamp on the airframe are the same bead.
        bead: new SphereGeometry(geometry.footRadius, 10, 10),
        gimbalYoke: new BoxGeometry(0.055 * scale, 0.03 * scale, 0.03 * scale),
        gimbalBall: new SphereGeometry(0.036 * scale, 20, 16),
        gimbalLens: new CylinderGeometry(
            0.019 * scale,
            0.019 * scale,
            0.006 * scale,
            18,
        ),
        sensorPod: new CylinderGeometry(
            0.03 * scale,
            0.034 * scale,
            0.022 * scale,
            18,
        ),
        sensorLens: new CircleGeometry(0.026 * scale, 20),
    };

    const materials = {
        carbon: new MeshStandardMaterial({
            color: geometry.livery,
            metalness: 0.5,
            roughness: 0.42,
        }),
        dome: new MeshStandardMaterial({
            color: geometry.accent,
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

type AirframeResources = ReturnType<typeof createAirframeResources>;

/**
 * Every airframe's resources, built on first use and kept for the session.
 *
 * Deliberately module scope rather than per mounted component, and it is the
 * same decision the single stock set was built on before the fleet existed:
 * these survive navigating between missions, so the GPU is not asked to
 * re-upload the same buffers each time a pilot opens a mission page, and a
 * pilot flipping through the fleet to compare airframes pays for each one
 * once rather than once per look.
 *
 * Bounded by the size of the fleet, which is a seeded catalogue of five —
 * a couple of dozen small procedural primitives each, against the two
 * megabytes of physics engine the same page already carries. Keyed on the
 * drone's slug rather than on the geometry object so remounting a component
 * with equal dimensions reuses what is already resident.
 *
 * Reached through a function rather than held in a hook because the frame
 * loop *writes* to these materials — blade opacity, strobe intensity, the
 * status lamp's colour — sixty times a second. A value React handed out is
 * one React is entitled to assume nothing modifies, and neither a memo nor a
 * ref may be mutated or read this way. These are not render state at all;
 * they are the airframe's own, and they live where that is true.
 */
const sharedResources = new Map<string, AirframeResources>();

function airframeResources(
    slug: string,
    geometry: AirframeGeometry,
): AirframeResources {
    let resources = sharedResources.get(slug);

    if (!resources) {
        resources = createAirframeResources(geometry);
        sharedResources.set(slug, resources);
    }

    return resources;
}

export function DroneModel({
    flightState: flightStateProp,
    drone,
}: {
    /** Omitted in showcase renders (marketing page): idles with slow props. */
    flightState?: FlightVisualState;
    /**
     * The airframe to draw.
     *
     * Required, and deliberately not defaulted to a copy of the stock
     * hexacopter's dimensions kept here. A second set of those numbers would
     * be a second thing to keep in step with the seeder, and the one that
     * drifted would drift on the landing page — where nobody is flying, so
     * nothing would look wrong until a visitor signed up and met a different
     * drone. Both callers have a real one: the cockpit resolves the pilot's,
     * and the marketing showcase is sent the fleet default.
     */
    drone: DroneModelSummary;
}) {
    const [showcaseState] = useState(() => {
        const state = createFlightVisualState();
        state.rotorSpeed = 14;

        return state;
    });
    const flightState = flightStateProp ?? showcaseState;

    const geometry = useMemo(
        () => airframeGeometry(drone.airframe, drone.flight.restHeight),
        [drone.airframe, drone.flight.restHeight],
    );
    const { geometries, materials } = airframeResources(drone.slug, geometry);

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

        // Adjacent rotors turn opposite ways so the yaw torques cancel,
        // which is what the alternating sign here is.
        propRefs.current.forEach((prop, index) => {
            if (prop) {
                const direction = index % 2 === 0 ? 1 : -1;
                prop.rotation.y += flightState.rotorSpeed * dt * direction;
            }
        });

        // The airframe's own materials, not values this render owns: one
        // blade material fades every blade, one strobe flashes every strobe,
        // and the loop writes to each of them once.
        const animated = airframeResources(drone.slug, geometry).materials;

        // Crossfade blades into a translucent disc as the rotors spool up.
        // Measured against this airframe's own full-throttle speed, so a
        // Freighter's slow rotors blur at the same fraction of their range
        // as a Vector's fast ones.
        const blur = clamp(
            (flightState.rotorSpeed - geometry.rotorMaxSpeed * 0.12) /
                (geometry.rotorMaxSpeed * 0.55),
            0,
            1,
        );
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

    const scale = geometry.bodyRadius / REFERENCE_BODY_RADIUS;

    return (
        <group ref={tiltRef}>
            {/* Carbon core, waist trim, and belly plate. The prism's flats
                face the booms, so each one leaves the body square-on. */}
            <mesh
                castShadow
                geometry={geometries.core}
                material={materials.carbon}
            />
            <mesh
                position={[0, 0.036 * scale, 0]}
                geometry={geometries.coreTrim}
                material={materials.trim}
            />
            <mesh
                position={[0, -0.055 * scale, 0]}
                castShadow
                geometry={geometries.belly}
                material={materials.dark}
            />

            {/* Brushed sensor dome */}
            <mesh
                position={[0, 0.046 * scale, 0]}
                geometry={geometries.domeCollar}
                material={materials.trim}
            />
            <mesh
                position={[0, 0.05 * scale, 0]}
                scale={[1, 0.66, 1]}
                castShadow
                geometry={geometries.dome}
                material={materials.dome}
            />

            {/* Battery slung under the tail of the core */}
            <mesh
                position={[0, -0.078 * scale, 0.055 * scale]}
                castShadow
                geometry={geometries.battery}
                material={materials.dark}
            />

            {/* Downward vision sensor: the drone's own light source */}
            <group position={[0, -0.076 * scale, 0]}>
                <mesh
                    geometry={geometries.sensorPod}
                    material={materials.motor}
                />
                <mesh
                    position={[0, -0.013 * scale, 0]}
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
            <group position={[0, -0.05 * scale, -geometry.bodyRadius * 0.967]}>
                <mesh
                    position={[0, 0.028 * scale, 0.03 * scale]}
                    geometry={geometries.gimbalYoke}
                    material={materials.dark}
                />
                <group ref={gimbalRef}>
                    <mesh
                        geometry={geometries.gimbalBall}
                        material={materials.dark}
                    />
                    <mesh
                        position={[0, 0, -0.031 * scale]}
                        rotation-x={Math.PI / 2}
                        geometry={geometries.gimbalLens}
                        material={materials.gimbalLens}
                    />
                </group>
            </group>

            {/* Booms, motor pods, props, legs, lamps */}
            {geometry.boomBearings.map((bearing, index) => {
                const front = isFrontBoom(bearing);
                const rear = isRearBoom(bearing);
                const boomCenter = -(
                    geometry.boomRoot +
                    geometry.boomLength / 2
                );

                return (
                    <group key={bearing} rotation-y={-bearing}>
                        <mesh
                            position={[0, 0.012 * scale, boomCenter]}
                            rotation-x={Math.PI / 2}
                            castShadow
                            geometry={geometries.boom}
                            material={materials.carbon}
                        />

                        {/* Motor pod */}
                        <mesh
                            position={[
                                0,
                                geometry.motorPodY,
                                -geometry.motorReach,
                            ]}
                            castShadow
                            geometry={geometries.motorPod}
                            material={materials.motor}
                        />
                        <mesh
                            position={[
                                0,
                                geometry.motorCapY,
                                -geometry.motorReach,
                            ]}
                            geometry={geometries.motorCap}
                            material={materials.motorCap}
                        />

                        {/* Propeller: blades + blur disc */}
                        <group
                            position={[
                                0,
                                geometry.rotorPlaneY,
                                -geometry.motorReach,
                            ]}
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
                                    position={[
                                        side * geometry.bladeOffset,
                                        0,
                                        0,
                                    ]}
                                    rotation-x={side * 0.14}
                                    geometry={geometries.blade}
                                    material={materials.blade}
                                />
                            ))}
                        </group>
                        <mesh
                            position={[
                                0,
                                geometry.rotorPlaneY,
                                -geometry.motorReach,
                            ]}
                            rotation-x={-Math.PI / 2}
                            geometry={geometries.blurDisc}
                            material={materials.blurDisc}
                        />

                        {/* Boom lamp: red ahead of the beam, strobe behind */}
                        {(front || rear) && (
                            <mesh
                                position={[
                                    0,
                                    -0.006 * scale,
                                    -geometry.motorReach + 0.055 * scale,
                                ]}
                                geometry={geometries.boomLamp}
                                material={
                                    front
                                        ? materials.boomLamp
                                        : materials.strobe
                                }
                            />
                        )}

                        {/* Landing legs hang off the swept booms, so any pair
                            on the beam stays clear for the boom lamps. Their
                            feet stop exactly at the rest height the physics
                            model parks the body at. */}
                        {(front || rear) && (
                            <>
                                <mesh
                                    position={[
                                        0,
                                        geometry.legCenterY,
                                        -geometry.legRadialOffset,
                                    ]}
                                    castShadow
                                    geometry={geometries.leg}
                                    material={materials.dark}
                                />
                                <mesh
                                    position={[
                                        0,
                                        geometry.footCenterY,
                                        -geometry.legRadialOffset,
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
                position={[0, 0.02 * scale, geometry.bodyRadius * 0.947]}
                geometry={geometries.bead}
                material={materials.status}
            />
        </group>
    );
}
