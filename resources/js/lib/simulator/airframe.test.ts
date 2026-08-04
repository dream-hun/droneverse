import { describe, expect, it } from 'vitest';
import {
    ADJACENT_PROP_CLEARANCE,
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
    LEG_TOP_Y,
    MOTOR_REACH,
    PROP_RADIUS,
    ROTOR_PLANE_Y,
    TIP_SPAN,
} from './airframe';
import { REST_HEIGHT } from './physics';

/**
 * The airframe is drawn from these numbers, so what they are asserted
 * against is what the drone would look wrong doing: props that intersect,
 * feet that hover above the pad or sink through it, booms that do not leave
 * the body they are attached to.
 */
describe('hexacopter airframe', () => {
    it('carries six booms, evenly spaced around the ring', () => {
        expect(BOOM_BEARINGS).toHaveLength(6);

        const step = (2 * Math.PI) / BOOM_BEARINGS.length;

        BOOM_BEARINGS.forEach((bearing, index) => {
            const previous =
                BOOM_BEARINGS[
                    (index + BOOM_BEARINGS.length - 1) % BOOM_BEARINGS.length
                ];
            const gap = (bearing - previous + 2 * Math.PI) % (2 * Math.PI);

            expect(gap).toBeCloseTo(step, 6);
        });
    });

    it('sweeps a pair forward, a pair aft, and leaves a pair on the beam', () => {
        const front = BOOM_BEARINGS.filter(isFrontBoom);
        const rear = BOOM_BEARINGS.filter(isRearBoom);
        const beam = BOOM_BEARINGS.filter(
            (bearing) => !isFrontBoom(bearing) && !isRearBoom(bearing),
        );

        expect(front).toHaveLength(2);
        expect(rear).toHaveLength(2);
        expect(beam).toHaveLength(2);
    });

    it('mirrors every boom about the centreline', () => {
        // A boom's mirror is its bearing reflected through the nose-tail
        // axis. An airframe missing one would fly visibly lopsided.
        BOOM_BEARINGS.forEach((bearing) => {
            const mirrored = (2 * Math.PI - bearing) % (2 * Math.PI);
            const hasMirror = BOOM_BEARINGS.some(
                (other) => Math.abs(other - mirrored) < 1e-9,
            );

            expect(hasMirror).toBe(true);
        });
    });

    it('keeps neighbouring rotor discs from intersecting', () => {
        expect(ADJACENT_PROP_CLEARANCE).toBeGreaterThan(0);
    });

    it('lands its feet exactly on the ground at rest height', () => {
        // The physics model parks the body centre at REST_HEIGHT, so the
        // underside of a foot has to sit at exactly -REST_HEIGHT in body
        // space: any higher and the drone hovers on its pad, any lower and
        // the feet disappear into it.
        expect(FOOT_CENTER_Y - FOOT_RADIUS).toBeCloseTo(-REST_HEIGHT, 6);
    });

    it('joins each leg to the foot it stands on', () => {
        const legBottom = LEG_CENTER_Y - LEG_LENGTH / 2;

        expect(legBottom).toBeLessThanOrEqual(FOOT_CENTER_Y);
    });

    it('hangs the whole landing gear below the rotor plane', () => {
        // The legs are inboard of the motors but still under the swept
        // discs, which is fine on a real airframe and fine here — as long as
        // every part of them stays below the plane the rotors turn in.
        expect(LEG_RADIAL_OFFSET).toBeGreaterThan(MOTOR_REACH - PROP_RADIUS);
        expect(LEG_TOP_Y).toBeLessThan(ROTOR_PLANE_Y);
    });

    it('runs each boom from inside the core out past its motor', () => {
        expect(BOOM_ROOT).toBeLessThan(BODY_RADIUS);
        expect(BOOM_ROOT + BOOM_LENGTH).toBeGreaterThan(MOTOR_REACH);
    });

    it('fits through the narrowest gate a mission is authored with', () => {
        // CourseSeeder's slalom gates are the tightest in the catalogue at
        // 3 m; the airframe has to clear one with room for the pilot to be
        // imprecise, not merely to fit.
        expect(TIP_SPAN).toBeLessThan(3 / 2);
    });
});
