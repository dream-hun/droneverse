import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { describe, expect, it } from 'vitest';
import { airframeGeometry, isFrontBoom, isRearBoom } from './airframe';
import type { DroneAirframeSpec, DroneFlightSpec } from '@/types/drone';

/**
 * The airframe is drawn from these numbers, so what they are asserted
 * against is what the drone would look wrong doing: props that intersect,
 * feet that hover above the pad or sink through it, booms that do not leave
 * the body they are attached to.
 *
 * Checked against the fleet database/seeders/DroneSeeder actually seeds,
 * read from the same file it reads. A fixture copied into this file would be
 * a second fleet that could drift from the real one, and it would drift
 * silently — this suite would go on passing about drones nobody flies. The
 * cost is that adding a drone to the fleet can fail a test here, which is
 * precisely the point: an airframe whose props intersect should not reach a
 * pilot, and this is the only place that can say so.
 */

type FleetEntry = {
    slug: string;
    name: string;
    is_default: boolean;
    flight_spec: DroneFlightSpec;
    airframe_spec: DroneAirframeSpec;
};

const fleet: FleetEntry[] = JSON.parse(
    readFileSync(
        fileURLToPath(
            new URL(
                '../../../../database/seeders/drone-fleet.json',
                import.meta.url,
            ),
        ),
        'utf8',
    ),
);

/** The tightest gate in the catalogue, from CourseSeeder's slalom missions. */
const NARROWEST_GATE = 3;

function geometryFor(drone: FleetEntry) {
    return airframeGeometry(drone.airframe_spec, drone.flight_spec.restHeight);
}

describe('the fleet', () => {
    it('is not empty and names exactly one default airframe', () => {
        expect(fleet.length).toBeGreaterThan(0);
        expect(fleet.filter((drone) => drone.is_default)).toHaveLength(1);
    });

    it('gives every drone a distinct slug', () => {
        const slugs = fleet.map((drone) => drone.slug);

        expect(new Set(slugs).size).toBe(slugs.length);
    });

    it('keeps the default airframe on the constants the catalogue was balanced against', () => {
        // Every mission was authored against the stock hexacopter. If these
        // move, every mission's difficulty moves with them — silently, and
        // for every pilot at once, including the ones who never opened the
        // picker. That is a decision to make deliberately, and changing this
        // test is how it gets made.
        const surveyor = fleet.find((drone) => drone.is_default);

        expect(surveyor?.flight_spec).toMatchObject({
            cruiseSpeed: 5,
            minCruiseSpeed: 1,
            maxCruiseSpeed: 8,
            maxClimbRate: 3,
            maxDescentRate: 2.5,
            horizontalAcceleration: 4,
            verticalAcceleration: 3,
            brakingAcceleration: 3.2,
            maxYawRate: Math.PI,
            yawAcceleration: Math.PI * 2,
            maxTilt: 0.38,
            spoolSeconds: 0.6,
            restHeight: 0.15,
        });
        expect(surveyor?.airframe_spec).toMatchObject({
            rotors: 6,
            bodyRadius: 0.15,
            motorReach: 0.3,
            propRadius: 0.145,
        });
    });
});

describe.each(fleet.map((drone) => [drone.name, drone] as const))(
    '%s airframe',
    (_name, drone) => {
        const geometry = geometryFor(drone);

        it('carries at least four booms, evenly spaced around the ring', () => {
            expect(geometry.boomBearings.length).toBe(
                drone.airframe_spec.rotors,
            );
            expect(geometry.boomBearings.length).toBeGreaterThanOrEqual(4);

            const step = (2 * Math.PI) / geometry.boomBearings.length;

            geometry.boomBearings.forEach((bearing, index) => {
                const previous =
                    geometry.boomBearings[
                        (index + geometry.boomBearings.length - 1) %
                            geometry.boomBearings.length
                    ];
                const gap = (bearing - previous + 2 * Math.PI) % (2 * Math.PI);

                expect(gap).toBeCloseTo(step, 6);
            });
        });

        it('turns its rotors in alternating directions', () => {
            // The mesh spins even-indexed rotors one way and odd the other,
            // which only cancels the yaw torque if there are an even number
            // of them. An odd-rotor airframe would spin on the spot.
            expect(geometry.boomBearings.length % 2).toBe(0);
        });

        it('mirrors every boom about the centreline', () => {
            // A boom's mirror is its bearing reflected through the nose-tail
            // axis. An airframe missing one would fly visibly lopsided.
            geometry.boomBearings.forEach((bearing) => {
                const mirrored = (2 * Math.PI - bearing) % (2 * Math.PI);
                const hasMirror = geometry.boomBearings.some(
                    (other) => Math.abs(other - mirrored) < 1e-9,
                );

                expect(hasMirror).toBe(true);
            });
        });

        it('leaves the nose and tail clear of booms', () => {
            // The gimbal hangs off the nose and the status lamp off the tail,
            // so nothing may sit on the centreline at either end.
            geometry.boomBearings.forEach((bearing) => {
                expect(Math.abs(Math.sin(bearing))).toBeGreaterThan(1e-6);
            });
        });

        it('sweeps at least one pair forward and one pair aft', () => {
            // The landing gear and the navigation lamps hang off the swept
            // booms. An airframe with none would have neither.
            expect(
                geometry.boomBearings.filter(isFrontBoom).length,
            ).toBeGreaterThanOrEqual(2);
            expect(
                geometry.boomBearings.filter(isRearBoom).length,
            ).toBeGreaterThanOrEqual(2);
        });

        it('keeps neighbouring rotor discs from intersecting', () => {
            expect(geometry.adjacentPropClearance).toBeGreaterThan(0);
        });

        it('lands its feet exactly on the ground at rest height', () => {
            // The physics model parks the body centre at restHeight, so the
            // underside of a foot has to sit at exactly -restHeight in body
            // space: any higher and the drone hovers on its pad, any lower
            // and the feet disappear into it.
            expect(geometry.footCenterY - geometry.footRadius).toBeCloseTo(
                -drone.flight_spec.restHeight,
                6,
            );
        });

        it('joins each leg to the foot it stands on', () => {
            const legBottom = geometry.legCenterY - geometry.legLength / 2;

            expect(legBottom).toBeLessThanOrEqual(geometry.footCenterY);
        });

        it('hangs the whole landing gear below the rotor plane', () => {
            // The legs are inboard of the motors but still under the swept
            // discs, which is fine on a real airframe and fine here — as long
            // as every part of them stays below the plane the rotors turn in.
            expect(geometry.legRadialOffset).toBeGreaterThan(
                geometry.motorReach - geometry.propRadius,
            );
            expect(geometry.legTopY).toBeLessThan(geometry.rotorPlaneY);
        });

        it('runs each boom from inside the core out past its motor', () => {
            expect(geometry.boomRoot).toBeLessThan(geometry.bodyRadius);
            expect(geometry.boomRoot + geometry.boomLength).toBeGreaterThan(
                geometry.motorReach,
            );
        });

        it('draws its blades out to the edge of the disc they sweep', () => {
            // A blade whose tip falls short of the blur disc pops as the
            // rotors spool up; one that overshoots sweeps outside the
            // clearance the test above just checked.
            const tip = geometry.bladeOffset + geometry.bladeLength / 2;

            expect(tip).toBeLessThanOrEqual(geometry.propRadius);
            expect(tip).toBeGreaterThan(geometry.propRadius * 0.9);
        });

        it('fits through the narrowest gate a mission is authored with', () => {
            // CourseSeeder's slalom gates are the tightest in the catalogue
            // at 3 m; an airframe has to clear one with room for the pilot to
            // be imprecise, not merely to fit.
            expect(geometry.tipSpan).toBeLessThan(NARROWEST_GATE / 2);
        });

        it('states an envelope a mission can actually be flown in', () => {
            const flight = drone.flight_spec;

            expect(flight.minCruiseSpeed).toBeGreaterThan(0);
            expect(flight.cruiseSpeed).toBeGreaterThanOrEqual(
                flight.minCruiseSpeed,
            );
            expect(flight.maxCruiseSpeed).toBeGreaterThanOrEqual(
                flight.cruiseSpeed,
            );
            expect(flight.maxClimbRate).toBeGreaterThan(0);
            expect(flight.maxDescentRate).toBeGreaterThan(0);
            expect(flight.horizontalAcceleration).toBeGreaterThan(0);
            expect(flight.verticalAcceleration).toBeGreaterThan(0);
            expect(flight.brakingAcceleration).toBeGreaterThan(0);
            expect(flight.maxYawRate).toBeGreaterThan(0);
            expect(flight.yawAcceleration).toBeGreaterThan(0);
            expect(flight.spoolSeconds).toBeGreaterThan(0);
            expect(flight.restHeight).toBeGreaterThan(0);
        });

        it('can hold a hover long enough to be worth taking off in', () => {
            // Battery is drained per second of flight and a mission runs to a
            // time limit. A drone that cannot outlast the longest authored
            // mission at a working throttle is one the pilot would watch die
            // mid-air with objectives still open.
            const drain =
                drone.flight_spec.batteryIdleDrain +
                drone.flight_spec.batteryThrottleDrain * 0.6;

            expect(100 / drain).toBeGreaterThan(180);
        });
    },
);
