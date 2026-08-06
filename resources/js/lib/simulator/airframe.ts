import type { DroneAirframeSpec, DroneFlightSpec } from '@/types/drone';

/**
 * Dimensions of one airframe, in meters.
 *
 * Separated from the mesh tree that draws it because these numbers are not
 * styling: they decide whether adjacent rotor discs intersect, whether the
 * landing feet meet the ground at the height the physics model parks the
 * body at, and whether the airframe fits through the gates missions are
 * authored with. Those are checkable claims, and they are checked in
 * `airframe.test.ts` — for every drone in the fleet, which is only possible
 * with the numbers somewhere a test can reach without standing up a WebGL
 * context.
 *
 * The fleet gives each drone a handful of defining dimensions
 * (`DroneAirframeSpec`) and the rest is derived here. That split is
 * deliberate: a seeder that had to state twenty consistent numbers per drone
 * would eventually state a set that is not consistent, and the failure would
 * be a drone whose feet hover above its pad rather than an error anyone
 * sees. What a drone author picks is what actually distinguishes airframes —
 * how many rotors, how far out, how big the discs — and everything that
 * simply has to agree with those follows from them.
 */
export type AirframeGeometry = {
    /** Motor count, and therefore boom count. */
    rotors: number;
    /** Circumradius of the prism core the booms leave from. */
    bodyRadius: number;
    /** Distance from the airframe's centre to each motor shaft. */
    motorReach: number;
    /** Where a boom emerges from the core. */
    boomRoot: number;
    /** Boom length, overlapping the core slightly so no seam shows. */
    boomLength: number;
    /** Radius of the disc a spinning rotor sweeps. */
    propRadius: number;
    /** One two-blade prop, tip to tip. */
    bladeLength: number;
    /** How far out from the hub each blade is drawn. */
    bladeOffset: number;
    motorPodY: number;
    motorCapY: number;
    /** Height of the plane the rotors sweep, above the airframe's centre. */
    rotorPlaneY: number;
    /** Where each landing leg meets the boom above it. */
    legTopY: number;
    legLength: number;
    footRadius: number;
    /** Legs hang inboard of the motors, clear of the rotor discs. */
    legRadialOffset: number;
    /** Leg mid-point, which is where a Y-axis cylinder is positioned from. */
    legCenterY: number;
    /**
     * Foot centre, placed so the underside of the foot is exactly on the
     * ground when the body sits at its resting height.
     */
    footCenterY: number;
    /** Boom bearings in radians, clockwise from the nose (-Z). */
    boomBearings: number[];
    /** Widest dimension of the airframe: rotor tip to opposing rotor tip. */
    tipSpan: number;
    /**
     * Clearance between the discs of two neighbouring rotors.
     *
     * Negative would mean the props intersect — visibly wrong, and wrong in
     * the way a real multirotor cannot be built.
     */
    adjacentPropClearance: number;
    /** Rotor speed at full throttle, in rad/s. Visual only. */
    rotorMaxSpeed: number;
    livery: string;
    accent: string;
};

/**
 * The body radius the fixed detail dimensions were originally drawn against.
 *
 * The gimbal, the sensor pod, the motor pods and the trim were authored as
 * absolute numbers on the stock hexacopter. Scaling them by the ratio of a
 * drone's core to this one keeps a Vector's motor pods proportionate to its
 * much smaller body instead of swallowing it, and is why the Surveyor's
 * derived numbers come out at exactly the constants it always had.
 */
export const REFERENCE_BODY_RADIUS = 0.15;

/**
 * Boom bearings for an evenly spaced ring of `rotors` motors.
 *
 * Offset by half a step so no boom sits on the centreline, which is what
 * leaves the nose clear for the gimbal and the tail clear for the status
 * lamp. On six motors this is the standard hex-X layout — a pair swept
 * forward, a pair square on the beam, a pair swept aft — and on four it is
 * the quad-X every small drone is built as.
 */
export function boomBearings(rotors: number): number[] {
    const step = (2 * Math.PI) / rotors;

    return Array.from(
        { length: rotors },
        (_, index) => step / 2 + index * step,
    );
}

/** True for booms swept forward of the beam. */
export function isFrontBoom(bearing: number): boolean {
    return Math.cos(bearing) > 0.2;
}

/** True for booms swept aft of the beam. */
export function isRearBoom(bearing: number): boolean {
    return Math.cos(bearing) < -0.2;
}

/**
 * Every dimension the mesh tree needs, derived from one drone's spec.
 *
 * `restHeight` comes from the flight spec rather than the airframe spec
 * because it is the physics that decides where a landed body sits; the
 * landing gear's job is to reach it. Passing the whole flight spec would
 * hand this function fifteen numbers to use one of, so it takes the one.
 */
export function airframeGeometry(
    airframe: DroneAirframeSpec,
    restHeight: DroneFlightSpec['restHeight'],
): AirframeGeometry {
    const scale = airframe.bodyRadius / REFERENCE_BODY_RADIUS;
    const boomRoot = airframe.bodyRadius * 0.8;
    const legTopY = -0.005 * scale;
    const footRadius = 0.013 * scale;
    const bearings = boomBearings(airframe.rotors);

    return {
        rotors: airframe.rotors,
        bodyRadius: airframe.bodyRadius,
        motorReach: airframe.motorReach,
        boomRoot,
        boomLength: airframe.motorReach - boomRoot + airframe.bodyRadius * 0.2,
        propRadius: airframe.propRadius,
        bladeLength: airframe.bladeLength,
        bladeOffset: airframe.propRadius * 0.51,
        motorPodY: 0.04 * scale,
        motorCapY: 0.068 * scale,
        rotorPlaneY: 0.082 * scale,
        legTopY,
        legLength: airframe.legLength,
        footRadius,
        legRadialOffset: airframe.motorReach * airframe.legReachRatio,
        legCenterY: legTopY - airframe.legLength / 2,
        footCenterY: -restHeight + footRadius,
        boomBearings: bearings,
        tipSpan: 2 * (airframe.motorReach + airframe.propRadius),
        adjacentPropClearance:
            2 * airframe.motorReach * Math.sin(Math.PI / airframe.rotors) -
            2 * airframe.propRadius,
        rotorMaxSpeed: airframe.rotorMaxSpeed,
        livery: airframe.livery,
        accent: airframe.accent,
    };
}
