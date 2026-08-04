import { REST_HEIGHT } from './physics';

/**
 * Dimensions of the inspection hexacopter, in meters.
 *
 * Separated from the mesh tree that draws it because these numbers are not
 * styling: they decide whether adjacent rotor discs intersect, whether the
 * landing feet meet the ground at the height the physics model parks the
 * body at, and whether the airframe fits through the gates missions are
 * authored with. Those are checkable claims, and they are checked in
 * `airframe.test.ts` — which is only possible with the numbers somewhere a
 * test can reach without standing up a WebGL context.
 */

/** Circumradius of the hex-prism core the booms leave from. */
export const BODY_RADIUS = 0.15;

/** Distance from the airframe's center to each motor shaft. */
export const MOTOR_REACH = 0.3;

/** Where a boom emerges from the core. */
export const BOOM_ROOT = 0.12;

/** Boom length, overlapping the core slightly so no seam shows. */
export const BOOM_LENGTH = MOTOR_REACH - BOOM_ROOT + 0.03;

/** Radius of the disc a spinning rotor sweeps. */
export const PROP_RADIUS = 0.145;

/** One two-blade prop, tip to tip. */
export const BLADE_LENGTH = 0.14;

export const MOTOR_POD_Y = 0.04;

export const MOTOR_CAP_Y = 0.068;

/** Height of the plane the rotors sweep, above the airframe's center. */
export const ROTOR_PLANE_Y = 0.082;

/** Where each landing leg meets the boom above it. */
export const LEG_TOP_Y = -0.005;

export const LEG_LENGTH = 0.14;

export const FOOT_RADIUS = 0.013;

/** Legs hang inboard of the motors, clear of the rotor discs. */
export const LEG_RADIAL_OFFSET = MOTOR_REACH * 0.62;

/** Leg mid-point, which is where a Y-axis cylinder is positioned from. */
export const LEG_CENTER_Y = LEG_TOP_Y - LEG_LENGTH / 2;

/**
 * Foot center, placed so the underside of the foot is exactly on the ground
 * when the body sits at its resting height.
 */
export const FOOT_CENTER_Y = -REST_HEIGHT + FOOT_RADIUS;

/**
 * Boom bearings in radians, clockwise from the nose (-Z).
 *
 * The hex-X layout: a pair swept forward, a pair square on the beam, a pair
 * swept aft. Nothing sits on the centreline, which is what leaves the nose
 * clear for the gimbal and the tail clear for the status lamp.
 */
export const BOOM_BEARINGS = [
    Math.PI / 6,
    Math.PI / 2,
    (5 * Math.PI) / 6,
    (7 * Math.PI) / 6,
    (3 * Math.PI) / 2,
    (11 * Math.PI) / 6,
];

/** Widest dimension of the airframe: rotor tip to opposing rotor tip. */
export const TIP_SPAN = 2 * (MOTOR_REACH + PROP_RADIUS);

/**
 * Clearance between the discs of two neighbouring rotors.
 *
 * Negative would mean the props intersect — visibly wrong, and wrong in the
 * way a real hex cannot be built.
 */
export const ADJACENT_PROP_CLEARANCE =
    2 * MOTOR_REACH * Math.sin(Math.PI / BOOM_BEARINGS.length) -
    2 * PROP_RADIUS;

/** True for the two booms swept forward of the beam. */
export function isFrontBoom(bearing: number): boolean {
    return Math.cos(bearing) > 0.2;
}

/** True for the two booms swept aft of the beam. */
export function isRearBoom(bearing: number): boolean {
    return Math.cos(bearing) < -0.2;
}
