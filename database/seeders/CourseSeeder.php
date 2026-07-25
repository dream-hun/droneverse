<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Course;
use Illuminate\Database\Seeder;

final class CourseSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        foreach ($this->courses() as $order => $definition) {
            $course = Course::query()->updateOrCreate(
                ['slug' => $definition['slug']],
                [
                    'title' => $definition['title'],
                    'description' => $definition['description'],
                    'difficulty' => $definition['difficulty'],
                    'order' => $order,
                    'is_published' => true,
                ],
            );

            foreach ($definition['challenges'] as $challengeOrder => $challenge) {
                $course->challenges()->updateOrCreate(
                    ['slug' => $challenge['slug']],
                    [
                        'title' => $challenge['title'],
                        'briefing' => $challenge['briefing'],
                        'order' => $challengeOrder,
                        'difficulty' => $challenge['difficulty'],
                        'starter_code' => $challenge['starter_code'],
                        'environment' => $challenge['environment'],
                        'success_criteria' => $challenge['success_criteria'],
                        'max_score' => 100,
                        'is_published' => true,
                    ],
                );
            }
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function courses(): array
    {
        return [
            [
                'slug' => 'drone-basics',
                'title' => 'Drone Basics',
                'description' => 'Learn to pilot a drone with code: takeoff and landing, waypoint navigation, obstacle avoidance, gate racing, and search patterns.',
                'difficulty' => 'beginner',
                'challenges' => $this->droneBasicsChallenges(),
            ],
            [
                'slug' => 'precision-flight',
                'title' => 'Precision Flight',
                'description' => 'Tight tolerances and exact flying: altitude control, slalom lines, low ceilings, and pinpoint landings.',
                'difficulty' => 'intermediate',
                'challenges' => $this->precisionFlightChallenges(),
            ],
            [
                'slug' => 'sensor-flight',
                'title' => 'Sensor Flight',
                'description' => 'Fly by feedback instead of fixed scripts: probe with the rangefinder, read position telemetry, and let the data steer the drone.',
                'difficulty' => 'advanced',
                'challenges' => $this->sensorFlightChallenges(),
            ],
            [
                'slug' => 'delivery-ops',
                'title' => 'Delivery Ops',
                'description' => 'Timed multi-stop delivery routes through a compact city block: plan clean lines, clear the rooftops, beat the clock.',
                'difficulty' => 'advanced',
                'challenges' => $this->deliveryOpsChallenges(),
            ],
            [
                'slug' => 'city-operations',
                'title' => 'City Operations',
                'description' => 'Full urban mission profiles in a living city block: sweep the streets with the object scanner, shoot survey photos that land in your photo log, run the drone wash, and graduate with a combined full-shift operation.',
                'difficulty' => 'advanced',
                'challenges' => $this->cityOperationsChallenges(),
            ],
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function droneBasicsChallenges(): array
    {
        return [
            [
                'slug' => 'hover-and-land',
                'title' => 'Hover & Land',
                'difficulty' => 'beginner',
                'briefing' => "Every flight starts the same way: take off, hold a steady hover, and land safely.\n\nUse `await drone.takeoff()` to lift off, `await drone.hover(seconds)` to hold position, and `await drone.land()` to touch back down on the pad.",
                'starter_code' => <<<'JS'
                    async function main(drone) {
                        await drone.takeoff();
                        await drone.hover(2);
                        await drone.land();
                    }
                    JS,
                'environment' => [
                    'start' => ['x' => 0, 'y' => 0, 'z' => 0, 'yaw' => 0],
                    'bounds' => ['width' => 20, 'depth' => 20, 'height' => 10],
                    'obstacles' => [],
                    'gates' => [],
                    'waypoints' => [],
                    'goal' => ['x' => 0, 'z' => 0, 'radius' => 1.2],
                ],
                'success_criteria' => [
                    'type' => 'waypoints',
                    'waypoints' => [],
                    'min_altitude' => 1.0,
                    'avoid_collisions' => false,
                    'max_time_seconds' => 30,
                    'landing_required' => true,
                ],
            ],
            [
                'slug' => 'waypoint-navigation',
                'title' => 'Waypoint Navigation',
                'difficulty' => 'beginner',
                'briefing' => "Fly the drone through three waypoints, in order, then land.\n\nUse `await drone.moveForward(distance)` to move along the way the drone is facing, and `await drone.turn(degrees)` to rotate (positive turns right).",
                'starter_code' => <<<'JS'
                    async function main(drone) {
                        await drone.takeoff();

                        // Waypoint 1: fly straight ahead
                        await drone.moveForward(5);

                        // Waypoint 2: turn right and fly again
                        await drone.turn(90);
                        await drone.moveForward(5);

                        // Waypoint 3: turn right once more
                        await drone.turn(90);
                        await drone.moveForward(5);

                        await drone.land();
                    }
                    JS,
                'environment' => [
                    'start' => ['x' => 0, 'y' => 0, 'z' => 0, 'yaw' => 0],
                    'bounds' => ['width' => 24, 'depth' => 24, 'height' => 10],
                    'obstacles' => [],
                    'gates' => [],
                    'waypoints' => [
                        ['x' => 0, 'y' => 1.5, 'z' => -5, 'radius' => 1.5],
                        ['x' => 5, 'y' => 1.5, 'z' => -5, 'radius' => 1.5],
                        ['x' => 5, 'y' => 1.5, 'z' => 0, 'radius' => 1.5],
                    ],
                    'goal' => ['x' => 5, 'z' => 0, 'radius' => 1.5],
                ],
                'success_criteria' => [
                    'type' => 'waypoints',
                    'waypoints' => [
                        ['x' => 0, 'y' => 1.5, 'z' => -5, 'radius' => 1.5],
                        ['x' => 5, 'y' => 1.5, 'z' => -5, 'radius' => 1.5],
                        ['x' => 5, 'y' => 1.5, 'z' => 0, 'radius' => 1.5],
                    ],
                    'avoid_collisions' => false,
                    'max_time_seconds' => 60,
                    'landing_required' => true,
                ],
            ],
            [
                'slug' => 'obstacle-avoidance',
                'title' => 'Obstacle Avoidance',
                'difficulty' => 'intermediate',
                'briefing' => "A corridor of crates blocks the direct path to the goal marker. Use `await drone.getDistanceAhead()` to sense obstacles and steer around them without colliding.\n\nColliding costs stars, but won't fail the run outright.",
                'starter_code' => <<<'JS'
                    async function main(drone) {
                        await drone.takeoff();

                        for (let i = 0; i < 4; i++) {
                            const distance = await drone.getDistanceAhead();

                            if (distance < 3) {
                                await drone.turn(-45);
                                await drone.moveForward(3);
                                await drone.turn(45);
                            } else {
                                await drone.moveForward(4);
                            }
                        }

                        await drone.land();
                    }
                    JS,
                'environment' => [
                    'start' => ['x' => 0, 'y' => 0, 'z' => 0, 'yaw' => 0],
                    'bounds' => ['width' => 20, 'depth' => 40, 'height' => 10],
                    'obstacles' => [
                        ['type' => 'box', 'x' => -2, 'y' => 1, 'z' => -6, 'sx' => 1.5, 'sy' => 2, 'sz' => 1.5],
                        ['type' => 'box', 'x' => 2, 'y' => 1, 'z' => -10, 'sx' => 1.5, 'sy' => 2, 'sz' => 1.5],
                        ['type' => 'box', 'x' => -2, 'y' => 1, 'z' => -14, 'sx' => 1.5, 'sy' => 2, 'sz' => 1.5],
                    ],
                    'gates' => [],
                    'waypoints' => [],
                    'goal' => ['x' => 0, 'z' => -18, 'radius' => 1.5],
                ],
                'success_criteria' => [
                    'type' => 'waypoints',
                    'waypoints' => [
                        ['x' => 0, 'y' => 1.5, 'z' => -18, 'radius' => 1.5],
                    ],
                    'avoid_collisions' => true,
                    'max_time_seconds' => 60,
                    'landing_required' => true,
                ],
            ],
            [
                'slug' => 'gate-race',
                'title' => 'Gate Race',
                'difficulty' => 'intermediate',
                'briefing' => "Race through all four gates in order, then land. Precision matters more than speed here — miss a gate and it won't count.",
                'starter_code' => <<<'JS'
                    async function main(drone) {
                        await drone.takeoff();

                        await drone.moveForward(6);
                        await drone.turn(45);
                        await drone.moveForward(6);
                        await drone.turn(-45);
                        await drone.moveForward(6);
                        await drone.turn(-45);
                        await drone.moveForward(6);

                        await drone.land();
                    }
                    JS,
                'environment' => [
                    'start' => ['x' => 0, 'y' => 0, 'z' => 0, 'yaw' => 0],
                    'bounds' => ['width' => 30, 'depth' => 44, 'height' => 10],
                    'obstacles' => [],
                    'gates' => [
                        ['x' => 0, 'y' => 1.5, 'z' => -6, 'width' => 3, 'height' => 3, 'rotationY' => 0],
                        ['x' => 4, 'y' => 1.5, 'z' => -10, 'width' => 3, 'height' => 3, 'rotationY' => 0.78],
                        ['x' => 4, 'y' => 1.5, 'z' => -16, 'width' => 3, 'height' => 3, 'rotationY' => -0.78],
                        ['x' => 0, 'y' => 1.5, 'z' => -20, 'width' => 3, 'height' => 3, 'rotationY' => 0],
                    ],
                    'waypoints' => [],
                    'goal' => ['x' => 0, 'z' => -20, 'radius' => 1.5],
                ],
                'success_criteria' => [
                    'type' => 'gates',
                    'waypoints' => [
                        ['x' => 0, 'y' => 1.5, 'z' => -6, 'radius' => 1.2],
                        ['x' => 4, 'y' => 1.5, 'z' => -10, 'radius' => 1.2],
                        ['x' => 4, 'y' => 1.5, 'z' => -16, 'radius' => 1.2],
                        ['x' => 0, 'y' => 1.5, 'z' => -20, 'radius' => 1.2],
                    ],
                    'avoid_collisions' => false,
                    'max_time_seconds' => 45,
                    'landing_required' => true,
                ],
            ],
            [
                'slug' => 'search-pattern',
                'title' => 'Search Pattern',
                'difficulty' => 'advanced',
                'briefing' => 'Sweep the whole field to find every marker: visit all four corners, then the center, before landing. A boustrophedon (back-and-forth) pattern covers ground efficiently.',
                'starter_code' => <<<'JS'
                    async function main(drone) {
                        await drone.takeoff();

                        const legs = [
                            [8, 0], [0, 8], [-8, 0], [0, -8], [0, 0],
                        ];

                        for (const [dx, dz] of legs) {
                            await drone.moveTo(dx, 1.5, dz);
                        }

                        await drone.land();
                    }
                    JS,
                'environment' => [
                    'start' => ['x' => 0, 'y' => 0, 'z' => 0, 'yaw' => 0],
                    'bounds' => ['width' => 26, 'depth' => 26, 'height' => 10],
                    'obstacles' => [],
                    'gates' => [],
                    'waypoints' => [
                        ['x' => 8, 'y' => 1.5, 'z' => 0, 'radius' => 2.5],
                        ['x' => 0, 'y' => 1.5, 'z' => 8, 'radius' => 2.5],
                        ['x' => -8, 'y' => 1.5, 'z' => 0, 'radius' => 2.5],
                        ['x' => 0, 'y' => 1.5, 'z' => -8, 'radius' => 2.5],
                        ['x' => 0, 'y' => 1.5, 'z' => 0, 'radius' => 2.5],
                    ],
                    'goal' => ['x' => 0, 'z' => 0, 'radius' => 2.5],
                ],
                'success_criteria' => [
                    'type' => 'waypoints',
                    'waypoints' => [
                        ['x' => 8, 'y' => 1.5, 'z' => 0, 'radius' => 2.5],
                        ['x' => 0, 'y' => 1.5, 'z' => 8, 'radius' => 2.5],
                        ['x' => -8, 'y' => 1.5, 'z' => 0, 'radius' => 2.5],
                        ['x' => 0, 'y' => 1.5, 'z' => -8, 'radius' => 2.5],
                        ['x' => 0, 'y' => 1.5, 'z' => 0, 'radius' => 2.5],
                    ],
                    'avoid_collisions' => false,
                    'max_time_seconds' => 90,
                    'landing_required' => true,
                ],
            ],
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function precisionFlightChallenges(): array
    {
        return [
            [
                'slug' => 'altitude-ladder',
                'title' => 'Altitude Ladder',
                'difficulty' => 'intermediate',
                'briefing' => "Three markers sit at different heights along the flight line: 3 m, 5 m, then back down to 1.5 m.\n\nUse `await drone.setAltitude(meters)` to climb or descend between forward legs, and clear at least 4 m at the top of the ladder.",
                'starter_code' => <<<'JS'
                    async function main(drone) {
                        await drone.takeoff();

                        await drone.setAltitude(3);
                        await drone.moveForward(4);

                        // Two more rungs: 5 m, then descend to 1.5 m.

                        await drone.land();
                    }
                    JS,
                'environment' => [
                    'start' => ['x' => 0, 'y' => 0, 'z' => 0, 'yaw' => 0],
                    'bounds' => ['width' => 20, 'depth' => 28, 'height' => 10],
                    'obstacles' => [],
                    'gates' => [],
                    'waypoints' => [
                        ['x' => 0, 'y' => 3, 'z' => -4, 'radius' => 1.2],
                        ['x' => 0, 'y' => 5, 'z' => -8, 'radius' => 1.2],
                        ['x' => 0, 'y' => 1.5, 'z' => -12, 'radius' => 1.2],
                    ],
                    'goal' => ['x' => 0, 'z' => -12, 'radius' => 1.5],
                ],
                'success_criteria' => [
                    'type' => 'waypoints',
                    'waypoints' => [
                        ['x' => 0, 'y' => 3, 'z' => -4, 'radius' => 1.2],
                        ['x' => 0, 'y' => 5, 'z' => -8, 'radius' => 1.2],
                        ['x' => 0, 'y' => 1.5, 'z' => -12, 'radius' => 1.2],
                    ],
                    'min_altitude' => 4.0,
                    'avoid_collisions' => false,
                    'max_time_seconds' => 60,
                    'landing_required' => true,
                ],
            ],
            [
                'slug' => 'slalom-run',
                'title' => 'Slalom Run',
                'difficulty' => 'intermediate',
                'briefing' => "Three pylons stand in a line down the course. Weave through the markers — right of the first pylon, left of the second, right of the third — then straighten out and land.\n\n`await drone.moveTo(x, y, z)` flies straight lines between points; brushing a pylon costs a star.",
                'starter_code' => <<<'JS'
                    async function main(drone) {
                        await drone.takeoff();

                        await drone.moveTo(2.5, 1.5, -5);
                        await drone.moveTo(-2.5, 1.5, -10);

                        // Weave past the last pylon and finish at the pad.

                        await drone.land();
                    }
                    JS,
                'environment' => [
                    'start' => ['x' => 0, 'y' => 0, 'z' => 0, 'yaw' => 0],
                    'bounds' => ['width' => 24, 'depth' => 44, 'height' => 10],
                    'obstacles' => [
                        ['type' => 'box', 'x' => 0, 'y' => 1.5, 'z' => -5, 'sx' => 1.2, 'sy' => 3, 'sz' => 1.2],
                        ['type' => 'box', 'x' => 0, 'y' => 1.5, 'z' => -10, 'sx' => 1.2, 'sy' => 3, 'sz' => 1.2],
                        ['type' => 'box', 'x' => 0, 'y' => 1.5, 'z' => -15, 'sx' => 1.2, 'sy' => 3, 'sz' => 1.2],
                    ],
                    'gates' => [],
                    'waypoints' => [
                        ['x' => 2.5, 'y' => 1.5, 'z' => -5, 'radius' => 1.2],
                        ['x' => -2.5, 'y' => 1.5, 'z' => -10, 'radius' => 1.2],
                        ['x' => 2.5, 'y' => 1.5, 'z' => -15, 'radius' => 1.2],
                        ['x' => 0, 'y' => 1.5, 'z' => -19, 'radius' => 1.2],
                    ],
                    'goal' => ['x' => 0, 'z' => -19, 'radius' => 1.5],
                ],
                'success_criteria' => [
                    'type' => 'waypoints',
                    'waypoints' => [
                        ['x' => 2.5, 'y' => 1.5, 'z' => -5, 'radius' => 1.2],
                        ['x' => -2.5, 'y' => 1.5, 'z' => -10, 'radius' => 1.2],
                        ['x' => 2.5, 'y' => 1.5, 'z' => -15, 'radius' => 1.2],
                        ['x' => 0, 'y' => 1.5, 'z' => -19, 'radius' => 1.2],
                    ],
                    'avoid_collisions' => true,
                    'max_time_seconds' => 60,
                    'landing_required' => true,
                ],
            ],
            [
                'slug' => 'low-ceiling',
                'title' => 'Low Ceiling',
                'difficulty' => 'intermediate',
                'briefing' => 'Overhead slabs hang above the corridor, and the markers sit close to the floor. Drop to 1.2 m with `await drone.setAltitude(1.2)` and hold that height the whole way through before landing at the far pad.',
                'starter_code' => <<<'JS'
                    async function main(drone) {
                        await drone.takeoff();

                        await drone.setAltitude(1.2);
                        await drone.moveForward(5);

                        // Two more markers wait further down the corridor.

                        await drone.land();
                    }
                    JS,
                'environment' => [
                    'start' => ['x' => 0, 'y' => 0, 'z' => 0, 'yaw' => 0],
                    'bounds' => ['width' => 20, 'depth' => 36, 'height' => 10],
                    'obstacles' => [
                        ['type' => 'box', 'x' => 0, 'y' => 2.4, 'z' => -5, 'sx' => 8, 'sy' => 0.4, 'sz' => 2.5],
                        ['type' => 'box', 'x' => 0, 'y' => 2.4, 'z' => -9, 'sx' => 8, 'sy' => 0.4, 'sz' => 2.5],
                        ['type' => 'box', 'x' => 0, 'y' => 2.4, 'z' => -13, 'sx' => 8, 'sy' => 0.4, 'sz' => 2.5],
                    ],
                    'gates' => [],
                    'waypoints' => [
                        ['x' => 0, 'y' => 1.2, 'z' => -5, 'radius' => 1.0],
                        ['x' => 0, 'y' => 1.2, 'z' => -9, 'radius' => 1.0],
                        ['x' => 0, 'y' => 1.2, 'z' => -13, 'radius' => 1.0],
                    ],
                    'goal' => ['x' => 0, 'z' => -16, 'radius' => 1.5],
                ],
                'success_criteria' => [
                    'type' => 'waypoints',
                    'waypoints' => [
                        ['x' => 0, 'y' => 1.2, 'z' => -5, 'radius' => 1.0],
                        ['x' => 0, 'y' => 1.2, 'z' => -9, 'radius' => 1.0],
                        ['x' => 0, 'y' => 1.2, 'z' => -13, 'radius' => 1.0],
                    ],
                    'avoid_collisions' => true,
                    'max_time_seconds' => 60,
                    'landing_required' => true,
                ],
            ],
            [
                'slug' => 'pinpoint-landing',
                'title' => 'Pinpoint Landing',
                'difficulty' => 'advanced',
                'briefing' => "An L-shaped approach with tight 0.8 m markers, ending on a small landing pad. Fly it clean: down the leg, right at the corner, right again, and set down inside the circle.\n\n`await drone.takeoff(2)` takes off straight to 2 m.",
                'starter_code' => <<<'JS'
                    async function main(drone) {
                        await drone.takeoff(2);

                        await drone.moveForward(6);

                        // Two right turns and two legs to reach the pad.

                        await drone.land();
                    }
                    JS,
                'environment' => [
                    'start' => ['x' => 0, 'y' => 0, 'z' => 0, 'yaw' => 0],
                    'bounds' => ['width' => 20, 'depth' => 20, 'height' => 10],
                    'obstacles' => [],
                    'gates' => [],
                    'waypoints' => [
                        ['x' => 0, 'y' => 2, 'z' => -6, 'radius' => 0.8],
                        ['x' => 4, 'y' => 2, 'z' => -6, 'radius' => 0.8],
                        ['x' => 4, 'y' => 2, 'z' => -2, 'radius' => 0.8],
                    ],
                    'goal' => ['x' => 4, 'z' => -2, 'radius' => 0.8],
                ],
                'success_criteria' => [
                    'type' => 'waypoints',
                    'waypoints' => [
                        ['x' => 0, 'y' => 2, 'z' => -6, 'radius' => 0.8],
                        ['x' => 4, 'y' => 2, 'z' => -6, 'radius' => 0.8],
                        ['x' => 4, 'y' => 2, 'z' => -2, 'radius' => 0.8],
                    ],
                    'avoid_collisions' => false,
                    'max_time_seconds' => 45,
                    'landing_required' => true,
                ],
            ],
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function sensorFlightChallenges(): array
    {
        return [
            [
                'slug' => 'wall-finder',
                'title' => 'Wall Finder',
                'difficulty' => 'advanced',
                'briefing' => "A wall blocks the course somewhere ahead — don't trust your eyes, trust the rangefinder. Creep forward with `await drone.getDistanceAhead()` until the wall is close, then slide right past its edge and finish behind it.\n\nThe rangefinder reads up to 20 m along the drone's current heading.",
                'starter_code' => <<<'JS'
                    async function main(drone) {
                        await drone.takeoff();

                        // Probe, step, repeat — stop before you hit the wall.
                        while (await drone.getDistanceAhead() > 2.5) {
                            await drone.moveForward(1);
                        }
                        console.log('Wall ahead!');

                        // Now find your way around it.

                        await drone.land();
                    }
                    JS,
                'environment' => [
                    'start' => ['x' => 0, 'y' => 0, 'z' => 0, 'yaw' => 0],
                    'bounds' => ['width' => 24, 'depth' => 30, 'height' => 10],
                    'obstacles' => [
                        ['type' => 'box', 'x' => 0, 'y' => 1.5, 'z' => -9, 'sx' => 10, 'sy' => 3, 'sz' => 1],
                    ],
                    'gates' => [],
                    'waypoints' => [
                        ['x' => 0, 'y' => 1.5, 'z' => -6.5, 'radius' => 1.5],
                        ['x' => 6.5, 'y' => 1.5, 'z' => -6.5, 'radius' => 1.5],
                        ['x' => 6.5, 'y' => 1.5, 'z' => -12, 'radius' => 1.5],
                    ],
                    'goal' => ['x' => 6.5, 'z' => -12, 'radius' => 1.5],
                ],
                'success_criteria' => [
                    'type' => 'waypoints',
                    'waypoints' => [
                        ['x' => 0, 'y' => 1.5, 'z' => -6.5, 'radius' => 1.5],
                        ['x' => 6.5, 'y' => 1.5, 'z' => -6.5, 'radius' => 1.5],
                        ['x' => 6.5, 'y' => 1.5, 'z' => -12, 'radius' => 1.5],
                    ],
                    'avoid_collisions' => true,
                    'max_time_seconds' => 60,
                    'landing_required' => true,
                ],
            ],
            [
                'slug' => 'canyon-corridor',
                'title' => 'Canyon Corridor',
                'difficulty' => 'advanced',
                'briefing' => "Walls hem in a narrow canyon with one exit on the right-hand side. Fly down the corridor on the rangefinder until the end wall closes in, then turn right and punch out through the gap.\n\nIn here, every collision counts against you.",
                'starter_code' => <<<'JS'
                    async function main(drone) {
                        await drone.takeoff();

                        while (await drone.getDistanceAhead() > 2) {
                            await drone.moveForward(1);
                        }

                        // The exit is somewhere to the right.

                        await drone.land();
                    }
                    JS,
                'environment' => [
                    'start' => ['x' => 0, 'y' => 0, 'z' => 0, 'yaw' => 0],
                    'bounds' => ['width' => 24, 'depth' => 24, 'height' => 10],
                    'obstacles' => [
                        ['type' => 'box', 'x' => -3, 'y' => 1.5, 'z' => -4, 'sx' => 0.5, 'sy' => 3, 'sz' => 9],
                        ['type' => 'box', 'x' => 3, 'y' => 1.5, 'z' => -2.5, 'sx' => 0.5, 'sy' => 3, 'sz' => 6],
                        ['type' => 'box', 'x' => 0, 'y' => 1.5, 'z' => -9, 'sx' => 6.5, 'sy' => 3, 'sz' => 0.5],
                    ],
                    'gates' => [],
                    'waypoints' => [
                        ['x' => 0, 'y' => 1.5, 'z' => -7, 'radius' => 1.2],
                        ['x' => 6, 'y' => 1.5, 'z' => -7, 'radius' => 1.2],
                    ],
                    'goal' => ['x' => 6, 'z' => -7, 'radius' => 1.5],
                ],
                'success_criteria' => [
                    'type' => 'waypoints',
                    'waypoints' => [
                        ['x' => 0, 'y' => 1.5, 'z' => -7, 'radius' => 1.2],
                        ['x' => 6, 'y' => 1.5, 'z' => -7, 'radius' => 1.2],
                    ],
                    'avoid_collisions' => true,
                    'max_time_seconds' => 60,
                    'landing_required' => true,
                ],
            ],
            [
                'slug' => 'homing-run',
                'title' => 'Homing Run',
                'difficulty' => 'advanced',
                'briefing' => "Fly the delivery loop as offsets, not fixed coordinates: 6 m east of wherever you are, then 6 m south of that, then straight home to the start pad.\n\n`await drone.getPosition()` returns `{ x, y, z }` — do the math on it and feed the result to `moveTo`.",
                'starter_code' => <<<'JS'
                    async function main(drone) {
                        await drone.takeoff();

                        const pos = await drone.getPosition();
                        console.log('Position:', pos);

                        await drone.moveTo(pos.x + 6, 1.5, pos.z);

                        // One more offset leg, then navigate home.

                        await drone.land();
                    }
                    JS,
                'environment' => [
                    'start' => ['x' => 0, 'y' => 0, 'z' => 0, 'yaw' => 0],
                    'bounds' => ['width' => 20, 'depth' => 20, 'height' => 10],
                    'obstacles' => [],
                    'gates' => [],
                    'waypoints' => [
                        ['x' => 6, 'y' => 1.5, 'z' => 0, 'radius' => 1.2],
                        ['x' => 6, 'y' => 1.5, 'z' => -6, 'radius' => 1.2],
                        ['x' => 0, 'y' => 1.5, 'z' => 0, 'radius' => 1.2],
                    ],
                    'goal' => ['x' => 0, 'z' => 0, 'radius' => 1.2],
                ],
                'success_criteria' => [
                    'type' => 'waypoints',
                    'waypoints' => [
                        ['x' => 6, 'y' => 1.5, 'z' => 0, 'radius' => 1.2],
                        ['x' => 6, 'y' => 1.5, 'z' => -6, 'radius' => 1.2],
                        ['x' => 0, 'y' => 1.5, 'z' => 0, 'radius' => 1.2],
                    ],
                    'avoid_collisions' => false,
                    'max_time_seconds' => 60,
                    'landing_required' => true,
                ],
            ],
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function deliveryOpsChallenges(): array
    {
        return [
            [
                'slug' => 'two-stop-run',
                'title' => 'Two-Stop Run',
                'difficulty' => 'intermediate',
                'briefing' => "Two delivery pads hide behind buildings on opposite sides of the street. Fly the center lane, cut behind each building to make the drop, and finish at the depot pad at the end of the block.\n\nClipping a building costs a star.",
                'starter_code' => <<<'JS'
                    async function main(drone) {
                        await drone.takeoff();

                        await drone.moveTo(0, 1.5, -8);
                        await drone.moveTo(-4, 1.5, -8);

                        // Second drop is behind the building across the street.

                        await drone.land();
                    }
                    JS,
                'environment' => [
                    'start' => ['x' => 0, 'y' => 0, 'z' => 0, 'yaw' => 0],
                    'bounds' => ['width' => 20, 'depth' => 36, 'height' => 10],
                    'obstacles' => [
                        ['type' => 'box', 'x' => -4, 'y' => 2, 'z' => -5, 'sx' => 2.5, 'sy' => 4, 'sz' => 2.5],
                        ['type' => 'box', 'x' => 4, 'y' => 2, 'z' => -9, 'sx' => 2.5, 'sy' => 4, 'sz' => 2.5],
                    ],
                    'gates' => [],
                    'waypoints' => [
                        ['x' => -4, 'y' => 1.5, 'z' => -8, 'radius' => 1.2],
                        ['x' => 4, 'y' => 1.5, 'z' => -12, 'radius' => 1.2],
                        ['x' => 0, 'y' => 1.5, 'z' => -15, 'radius' => 1.2],
                    ],
                    'goal' => ['x' => 0, 'z' => -15, 'radius' => 1.5],
                ],
                'success_criteria' => [
                    'type' => 'waypoints',
                    'waypoints' => [
                        ['x' => -4, 'y' => 1.5, 'z' => -8, 'radius' => 1.2],
                        ['x' => 4, 'y' => 1.5, 'z' => -12, 'radius' => 1.2],
                        ['x' => 0, 'y' => 1.5, 'z' => -15, 'radius' => 1.2],
                    ],
                    'avoid_collisions' => true,
                    'max_time_seconds' => 60,
                    'landing_required' => true,
                ],
            ],
            [
                'slug' => 'rooftop-drop',
                'title' => 'Rooftop Drop',
                'difficulty' => 'advanced',
                'briefing' => "This package goes to the roof. Climb over the 6 m tower, make the drop above the rooftop marker, then descend on the far side and land at the street-level pad.\n\nStay above 6.5 m while you're over the building.",
                'starter_code' => <<<'JS'
                    async function main(drone) {
                        await drone.takeoff();

                        await drone.setAltitude(7);
                        await drone.moveForward(8);

                        // You're over the roof — now get down to the street pad.

                        await drone.land();
                    }
                    JS,
                'environment' => [
                    'start' => ['x' => 0, 'y' => 0, 'z' => 0, 'yaw' => 0],
                    'bounds' => ['width' => 20, 'depth' => 32, 'height' => 12],
                    'obstacles' => [
                        ['type' => 'box', 'x' => 0, 'y' => 3, 'z' => -8, 'sx' => 3, 'sy' => 6, 'sz' => 3],
                    ],
                    'gates' => [],
                    'waypoints' => [
                        ['x' => 0, 'y' => 7, 'z' => -8, 'radius' => 1.2],
                        ['x' => 0, 'y' => 1.5, 'z' => -13, 'radius' => 1.2],
                    ],
                    'goal' => ['x' => 0, 'z' => -13, 'radius' => 1.5],
                ],
                'success_criteria' => [
                    'type' => 'waypoints',
                    'waypoints' => [
                        ['x' => 0, 'y' => 7, 'z' => -8, 'radius' => 1.2],
                        ['x' => 0, 'y' => 1.5, 'z' => -13, 'radius' => 1.2],
                    ],
                    'min_altitude' => 6.5,
                    'avoid_collisions' => true,
                    'max_time_seconds' => 60,
                    'landing_required' => true,
                ],
            ],
            [
                'slug' => 'rush-hour',
                'title' => 'Rush Hour',
                'difficulty' => 'advanced',
                'briefing' => "Four deliveries, one tight window. The stops zigzag across the block while support columns crowd the center line — plan diagonal legs that thread between them and keep moving.\n\nFinish fast enough and the speed star is yours.",
                'starter_code' => <<<'JS'
                    async function main(drone) {
                        await drone.takeoff();

                        await drone.moveTo(-6, 1.5, -4);

                        // Three more stops: (6, -8), (-6, -12), then (0, -16).

                        await drone.land();
                    }
                    JS,
                'environment' => [
                    'start' => ['x' => 0, 'y' => 0, 'z' => 0, 'yaw' => 0],
                    'bounds' => ['width' => 24, 'depth' => 40, 'height' => 10],
                    'obstacles' => [
                        ['type' => 'box', 'x' => 0, 'y' => 2, 'z' => -4, 'sx' => 1.2, 'sy' => 4, 'sz' => 1.2],
                        ['type' => 'box', 'x' => 0, 'y' => 2, 'z' => -8, 'sx' => 1.2, 'sy' => 4, 'sz' => 1.2],
                        ['type' => 'box', 'x' => 0, 'y' => 2, 'z' => -12, 'sx' => 1.2, 'sy' => 4, 'sz' => 1.2],
                    ],
                    'gates' => [],
                    'waypoints' => [
                        ['x' => -6, 'y' => 1.5, 'z' => -4, 'radius' => 1.2],
                        ['x' => 6, 'y' => 1.5, 'z' => -8, 'radius' => 1.2],
                        ['x' => -6, 'y' => 1.5, 'z' => -12, 'radius' => 1.2],
                        ['x' => 0, 'y' => 1.5, 'z' => -16, 'radius' => 1.2],
                    ],
                    'goal' => ['x' => 0, 'z' => -16, 'radius' => 1.5],
                ],
                'success_criteria' => [
                    'type' => 'waypoints',
                    'waypoints' => [
                        ['x' => -6, 'y' => 1.5, 'z' => -4, 'radius' => 1.2],
                        ['x' => 6, 'y' => 1.5, 'z' => -8, 'radius' => 1.2],
                        ['x' => -6, 'y' => 1.5, 'z' => -12, 'radius' => 1.2],
                        ['x' => 0, 'y' => 1.5, 'z' => -16, 'radius' => 1.2],
                    ],
                    'avoid_collisions' => true,
                    'max_time_seconds' => 75,
                    'landing_required' => true,
                ],
            ],
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function cityOperationsChallenges(): array
    {
        return [
            [
                'slug' => 'downtown-gauntlet',
                'title' => 'Downtown Gauntlet',
                'difficulty' => 'advanced',
                'briefing' => "The entry exam for city work. Four downtown towers box in a central avenue, and the manifest has stops at three heights: a street-level pickup in the avenue, a rooftop drop on the low block, and a curb-side delivery on the far service street — then home to the depot at the south end.\n\nNo single trick clears this. Plan the vertical profile (`setAltitude` / `moveTo` with a real y), climb above 8 m to clear the rooftop, feel your way around blind corners with `getDistanceAhead()`, and stay off the concrete — every clip costs a star. The streets below are parked up, so keep your lines clean. If you lose your bearings, `await drone.scan(30)` maps every tower around you.",
                'starter_code' => <<<'JS'
                    async function main(drone) {
                        await drone.takeoff(2);

                        // Stop 1 — pickup in the avenue mouth, between the first two towers.
                        await drone.moveTo(0, 2, -6);

                        // Stop 2 — rooftop drop on the low NE block. Climb ABOVE the
                        // roof before you slide over it, then settle on the marker.
                        await drone.setAltitude(9);
                        // await drone.moveTo(8, 9, -8);

                        // Stop 3 — curb delivery on the east service street. Drop back
                        // to street level only once you're clear of the tower.
                        // Tip: probe with `await drone.getDistanceAhead()` before you commit.

                        // Home — thread back to the central avenue and run south to the depot.

                        await drone.land();
                    }
                    JS,
                'environment' => [
                    'start' => ['x' => 0, 'y' => 0, 'z' => 0, 'yaw' => 0],
                    'bounds' => ['width' => 40, 'depth' => 64, 'height' => 20],
                    'obstacles' => [
                        // NW tower (tall) and NE block (low) flank the avenue entrance.
                        ['type' => 'box', 'x' => -8, 'y' => 7, 'z' => -8, 'sx' => 8, 'sy' => 14, 'sz' => 8, 'label' => 'nw-tower'],
                        ['type' => 'box', 'x' => 8, 'y' => 4, 'z' => -8, 'sx' => 8, 'sy' => 8, 'sz' => 8, 'label' => 'ne-block'],
                        // SW mid-rise and SE tower box in the far end of the avenue.
                        ['type' => 'box', 'x' => -8, 'y' => 5, 'z' => -24, 'sx' => 8, 'sy' => 10, 'sz' => 8, 'label' => 'sw-midrise'],
                        ['type' => 'box', 'x' => 8, 'y' => 8, 'z' => -24, 'sx' => 8, 'sy' => 16, 'sz' => 8, 'label' => 'se-tower'],
                    ],
                    'gates' => [],
                    'waypoints' => [
                        ['x' => 0, 'y' => 2, 'z' => -6, 'radius' => 1.5],
                        ['x' => 8, 'y' => 9, 'z' => -8, 'radius' => 1.8],
                        ['x' => 14, 'y' => 1.6, 'z' => -18, 'radius' => 1.8],
                        ['x' => 0, 'y' => 1.5, 'z' => -30, 'radius' => 1.6],
                    ],
                    'goal' => ['x' => 0, 'z' => -30, 'radius' => 1.6],
                    'wind' => ['speed' => 3.2, 'directionDeg' => 45],
                    'props' => [
                        ['kind' => 'car', 'x' => -3.5, 'z' => -4, 'rotationY' => 0.05],
                        ['kind' => 'car', 'x' => 3.5, 'z' => -12, 'rotationY' => 3.1],
                        ['kind' => 'car', 'x' => -3.5, 'z' => -20, 'rotationY' => -0.08],
                        ['kind' => 'van', 'x' => 15, 'z' => -13, 'rotationY' => 1.57, 'label' => 'service-van'],
                        ['kind' => 'tree', 'x' => -13, 'z' => -3],
                        ['kind' => 'tree', 'x' => 13, 'z' => -3],
                        ['kind' => 'tree', 'x' => -13, 'z' => -29],
                    ],
                ],
                'success_criteria' => [
                    'type' => 'waypoints',
                    'waypoints' => [
                        ['x' => 0, 'y' => 2, 'z' => -6, 'radius' => 1.5],
                        ['x' => 8, 'y' => 9, 'z' => -8, 'radius' => 1.8],
                        ['x' => 14, 'y' => 1.6, 'z' => -18, 'radius' => 1.8],
                        ['x' => 0, 'y' => 1.5, 'z' => -30, 'radius' => 1.6],
                    ],
                    'min_altitude' => 8.0,
                    'avoid_collisions' => true,
                    'max_time_seconds' => 120,
                    'landing_required' => true,
                ],
            ],
            [
                'slug' => 'street-sweep',
                'title' => 'Street Sweep',
                'difficulty' => 'advanced',
                'briefing' => "Dispatch lost track of a delivery van somewhere in the block. Your airframe carries an object scanner: `await drone.scan(range)` reports every object within range — nearest first — as `{ kind, label, x, y, z, distance, bearingDeg }`. Parked cars, trees, buildings, everything. The van answers to the label `delivery-van`.\n\nFind it, fly to it, and document it: `await drone.takePhoto('delivery-van')` captures the nose camera and files the shot in your photo log. The photo only counts if you shoot it within 5 m of the van, so get close before you press the shutter. Then bring it home to the pad.",
                'starter_code' => <<<'JS'
                    async function main(drone) {
                        await drone.takeoff(2);

                        // The scanner sees through walls: every contact within 30 m,
                        // nearest first, each with { kind, label, x, z, distance }.
                        const contacts = await drone.scan(30);
                        console.log('Contacts on scope:', contacts.length);

                        const van = contacts.find((c) => c.label === 'delivery-van');

                        if (van) {
                            console.log('Van located at', van.x, van.z);
                            // Fly to it and shoot the evidence photo:
                            // await drone.moveTo(van.x, 2.5, van.z + 3);
                            // await drone.takePhoto('delivery-van');
                        } else {
                            console.log('No van in range - push deeper and scan again.');
                        }

                        // Return to the pad before you land.
                        await drone.land();
                    }
                    JS,
                'environment' => [
                    'start' => ['x' => 0, 'y' => 0, 'z' => 0, 'yaw' => 0],
                    'bounds' => ['width' => 36, 'depth' => 48, 'height' => 14],
                    'obstacles' => [
                        ['type' => 'box', 'x' => -9, 'y' => 2.5, 'z' => -10, 'sx' => 6, 'sy' => 5, 'sz' => 6, 'label' => 'west-block'],
                        ['type' => 'box', 'x' => 9, 'y' => 3, 'z' => -10, 'sx' => 6, 'sy' => 6, 'sz' => 6, 'label' => 'east-block'],
                        ['type' => 'box', 'x' => -9, 'y' => 3.5, 'z' => -22, 'sx' => 7, 'sy' => 7, 'sz' => 7, 'label' => 'warehouse'],
                        ['type' => 'box', 'x' => 10, 'y' => 2.5, 'z' => -20, 'sx' => 5, 'sy' => 5, 'sz' => 5, 'label' => 'depot-annex'],
                    ],
                    'gates' => [],
                    'waypoints' => [],
                    'goal' => ['x' => 0, 'z' => 0, 'radius' => 1.5],
                    'props' => [
                        ['kind' => 'van', 'x' => 10, 'z' => -15, 'rotationY' => 0.35, 'label' => 'delivery-van'],
                        ['kind' => 'car', 'x' => -4, 'z' => -6, 'rotationY' => 0.02],
                        ['kind' => 'car', 'x' => 4, 'z' => -7, 'rotationY' => 3.19],
                        ['kind' => 'car', 'x' => -12.5, 'z' => -5, 'rotationY' => 1.6],
                        ['kind' => 'car', 'x' => 3, 'z' => -18, 'rotationY' => 1.52],
                        ['kind' => 'car', 'x' => -3, 'z' => -13, 'rotationY' => -0.06],
                        ['kind' => 'tree', 'x' => 0, 'z' => -9],
                        ['kind' => 'tree', 'x' => 0, 'z' => -16],
                        ['kind' => 'tree', 'x' => 14, 'z' => -7],
                    ],
                ],
                'success_criteria' => [
                    'type' => 'waypoints',
                    'waypoints' => [],
                    'min_photos' => 1,
                    'photo_targets' => [
                        ['x' => 10, 'z' => -15, 'radius' => 5, 'label' => 'delivery-van'],
                    ],
                    'avoid_collisions' => true,
                    'max_time_seconds' => 100,
                    'landing_required' => true,
                ],
            ],
            [
                'slug' => 'wash-and-return',
                'title' => 'Wash & Return',
                'difficulty' => 'advanced',
                'briefing' => "The morning survey runs low over the construction yard, and the airframe comes back caked in grit. Company policy is strict: no dirty drone lands on the depot pad.\n\nSweep both markers over the crate yard, then head for the DRONE WASH tunnel on the east side. Fly in one mouth and out the other — the wash beams at both ends have to see you pass, and the tunnel is solid, so line up straight and keep it low (the opening is 4.5 m wide and 3.5 m tall). Brushing a wall counts as a collision. Clean airframe, clean landing.",
                'starter_code' => <<<'JS'
                    async function main(drone) {
                        await drone.takeoff();

                        // Survey leg: two markers over the dusty crate yard.
                        await drone.moveTo(-8, 2, -14);
                        // ...the second marker waits at (-4, 2, -19).

                        // Wash cycle: the tunnel sits at x = 8 and runs north-south
                        // (mouths at z = -14 and z = -6). Line up outside one mouth,
                        // then fly straight through at about 1.6 m:
                        // await drone.moveTo(8, 1.6, -16);
                        // await drone.moveTo(8, 1.6, -4);

                        // Home to the pad.
                        await drone.land();
                    }
                    JS,
                'environment' => [
                    'start' => ['x' => 0, 'y' => 0, 'z' => 0, 'yaw' => 0],
                    'bounds' => ['width' => 30, 'depth' => 44, 'height' => 12],
                    'obstacles' => [
                        ['type' => 'box', 'x' => -10, 'y' => 2.5, 'z' => -8, 'sx' => 5, 'sy' => 5, 'sz' => 5, 'label' => 'site-office'],
                        ['type' => 'box', 'x' => -8, 'y' => 1, 'z' => -14, 'sx' => 1.8, 'sy' => 2, 'sz' => 1.8],
                        ['type' => 'box', 'x' => -10.5, 'y' => 0.9, 'z' => -16, 'sx' => 1.6, 'sy' => 1.8, 'sz' => 1.6],
                        ['type' => 'box', 'x' => -7, 'y' => 0.75, 'z' => -17.5, 'sx' => 1.5, 'sy' => 1.5, 'sz' => 1.5],
                    ],
                    'gates' => [],
                    'waypoints' => [
                        ['x' => -8, 'y' => 2, 'z' => -14, 'radius' => 1.5],
                        ['x' => -4, 'y' => 2, 'z' => -19, 'radius' => 1.5],
                    ],
                    'goal' => ['x' => 0, 'z' => 0, 'radius' => 1.5],
                    'carwash' => ['x' => 8, 'z' => -10, 'rotationY' => 0, 'label' => 'drone-wash'],
                    'props' => [
                        ['kind' => 'car', 'x' => 8, 'z' => -2, 'rotationY' => 3.14],
                        ['kind' => 'car', 'x' => 11.5, 'z' => -4, 'rotationY' => 2.9],
                        ['kind' => 'tree', 'x' => -2, 'z' => -4],
                        ['kind' => 'tree', 'x' => 3, 'z' => -16],
                    ],
                ],
                'success_criteria' => [
                    'type' => 'waypoints',
                    'waypoints' => [
                        ['x' => -8, 'y' => 2, 'z' => -14, 'radius' => 1.5],
                        ['x' => -4, 'y' => 2, 'z' => -19, 'radius' => 1.5],
                    ],
                    'wash_required' => true,
                    'avoid_collisions' => true,
                    'max_time_seconds' => 100,
                    'landing_required' => true,
                ],
            ],
            [
                'slug' => 'skyline-survey',
                'title' => 'Skyline Survey',
                'difficulty' => 'advanced',
                'briefing' => "The city archive wants three survey photos, and every one lands in your photo log: the plaza fountain, the supply yard, and the HQ tower rooftop.\n\nEach beacon marks a shot. Park the drone inside the beacon's ring and call `await drone.takePhoto(label)` — the frame is captured from the nose camera, so face something worth framing first (`turn` is your tripod head). The rooftop shot is the catch: the HQ roof tops out at 8 m and the target ring is tight, so you must climb above the parapet and shoot from directly over the roof. The run also demands a 9 m ceiling somewhere along the way — the rooftop pass covers both if you fly it right.",
                'starter_code' => <<<'JS'
                    async function main(drone) {
                        await drone.takeoff(2);

                        // Shot 1: the plaza fountain.
                        await drone.moveTo(-10, 2, -8);
                        await drone.takePhoto('fountain');

                        // Shot 2: the supply yard at (12, -14).

                        // Shot 3: the HQ rooftop at (0, -26). The roof is 8 m up -
                        // climb first (try 9.5 m), slide over the roof, then shoot.

                        await drone.land();
                    }
                    JS,
                'environment' => [
                    'start' => ['x' => 0, 'y' => 0, 'z' => 0, 'yaw' => 0],
                    'bounds' => ['width' => 40, 'depth' => 56, 'height' => 14],
                    'obstacles' => [
                        ['type' => 'cylinder', 'x' => -10, 'y' => 0.6, 'z' => -8, 'radius' => 1.8, 'height' => 1.2, 'label' => 'fountain'],
                        ['type' => 'box', 'x' => 12, 'y' => 1, 'z' => -14, 'sx' => 2, 'sy' => 2, 'sz' => 2, 'label' => 'supply-cache'],
                        ['type' => 'box', 'x' => 14, 'y' => 0.8, 'z' => -16.5, 'sx' => 1.6, 'sy' => 1.6, 'sz' => 1.6],
                        ['type' => 'box', 'x' => 0, 'y' => 4, 'z' => -26, 'sx' => 6, 'sy' => 8, 'sz' => 6, 'label' => 'hq-tower'],
                        ['type' => 'box', 'x' => -14, 'y' => 3, 'z' => -20, 'sx' => 5, 'sy' => 6, 'sz' => 5, 'label' => 'residence'],
                        ['type' => 'box', 'x' => 15, 'y' => 3.5, 'z' => -24, 'sx' => 6, 'sy' => 7, 'sz' => 6, 'label' => 'parking-tower'],
                    ],
                    'gates' => [],
                    'waypoints' => [
                        ['x' => -10, 'y' => 2, 'z' => -8, 'radius' => 3],
                        ['x' => 12, 'y' => 2, 'z' => -14, 'radius' => 3],
                        ['x' => 0, 'y' => 9.5, 'z' => -26, 'radius' => 2.5],
                    ],
                    'goal' => ['x' => 0, 'z' => 0, 'radius' => 1.5],
                    'props' => [
                        ['kind' => 'car', 'x' => -6, 'z' => -9, 'rotationY' => 1.55],
                        ['kind' => 'car', 'x' => 10, 'z' => -10, 'rotationY' => 0.1],
                        ['kind' => 'car', 'x' => 2, 'z' => -12, 'rotationY' => 3.2],
                        ['kind' => 'tree', 'x' => -4, 'z' => -4],
                        ['kind' => 'tree', 'x' => 5, 'z' => -6],
                        ['kind' => 'tree', 'x' => -12, 'z' => -14],
                    ],
                ],
                'success_criteria' => [
                    'type' => 'waypoints',
                    'waypoints' => [],
                    'min_photos' => 3,
                    'photo_targets' => [
                        ['x' => -10, 'z' => -8, 'radius' => 3, 'label' => 'fountain'],
                        ['x' => 12, 'z' => -14, 'radius' => 3, 'label' => 'supply-cache'],
                        ['x' => 0, 'z' => -26, 'radius' => 2.5, 'label' => 'hq-rooftop'],
                    ],
                    'min_altitude' => 9.0,
                    'avoid_collisions' => true,
                    'max_time_seconds' => 120,
                    'landing_required' => true,
                ],
            ],
            [
                'slug' => 'full-shift',
                'title' => 'Full Shift',
                'difficulty' => 'advanced',
                'briefing' => "One shift, every skill. The board reads like a whole day of city work:\n\n1. Street pickup in the avenue mouth between the towers.\n2. Rooftop drop on the low NE block — clear 9 m on the way over.\n3. Locate the van answering to `target-van` (scan for it), close to within 5 m, and photograph it.\n4. Run the DRONE WASH tunnel on the east service street.\n5. Land at the south depot before the clock dies.\n\nThe wind is up, the streets are parked full, and every clip costs a star. Chain everything you have: `scan`, `takePhoto`, `getDistanceAhead`, real 3D `moveTo` lines, and `setSpeed` when the corridor is clear. This is the graduation flight.",
                'starter_code' => <<<'JS'
                    async function main(drone) {
                        await drone.takeoff(2);

                        // 1. Street pickup in the avenue mouth.
                        await drone.moveTo(0, 2, -6);

                        // 2. Rooftop drop on the NE block - climb above 9 m first.
                        // await drone.setAltitude(9.5);
                        // await drone.moveTo(8, 9.5, -8);

                        // 3. Find and photograph the van labeled 'target-van'.
                        // const contacts = await drone.scan(40);
                        // const van = contacts.find((c) => c.label === 'target-van');

                        // 4. Wash cycle: tunnel at x = 16, mouths at z = -30 and z = -22.

                        // 5. South depot: land on the pad at (0, -30).
                        await drone.land();
                    }
                    JS,
                'environment' => [
                    'start' => ['x' => 0, 'y' => 0, 'z' => 0, 'yaw' => 0],
                    'bounds' => ['width' => 44, 'depth' => 64, 'height' => 18],
                    'obstacles' => [
                        ['type' => 'box', 'x' => -8, 'y' => 7, 'z' => -8, 'sx' => 8, 'sy' => 14, 'sz' => 8, 'label' => 'nw-tower'],
                        ['type' => 'box', 'x' => 8, 'y' => 4, 'z' => -8, 'sx' => 8, 'sy' => 8, 'sz' => 8, 'label' => 'ne-block'],
                        ['type' => 'box', 'x' => -8, 'y' => 5, 'z' => -24, 'sx' => 8, 'sy' => 10, 'sz' => 8, 'label' => 'sw-midrise'],
                        ['type' => 'box', 'x' => 8, 'y' => 8, 'z' => -24, 'sx' => 8, 'sy' => 16, 'sz' => 8, 'label' => 'se-tower'],
                    ],
                    'gates' => [],
                    'waypoints' => [
                        ['x' => 0, 'y' => 2, 'z' => -6, 'radius' => 1.5],
                        ['x' => 8, 'y' => 9.5, 'z' => -8, 'radius' => 1.8],
                    ],
                    'goal' => ['x' => 0, 'z' => -30, 'radius' => 1.6],
                    'wind' => ['speed' => 3.5, 'directionDeg' => 60],
                    'carwash' => ['x' => 16, 'z' => -26, 'rotationY' => 0, 'label' => 'drone-wash'],
                    'props' => [
                        ['kind' => 'van', 'x' => -15, 'z' => -18, 'rotationY' => 1.62, 'label' => 'target-van'],
                        ['kind' => 'car', 'x' => -3.5, 'z' => -14, 'rotationY' => 0.03],
                        ['kind' => 'car', 'x' => 3.5, 'z' => -16, 'rotationY' => 3.12],
                        ['kind' => 'car', 'x' => -3.5, 'z' => -27, 'rotationY' => -0.05],
                        ['kind' => 'car', 'x' => 16, 'z' => -18, 'rotationY' => 3.14],
                        ['kind' => 'tree', 'x' => -13, 'z' => -3],
                        ['kind' => 'tree', 'x' => 13, 'z' => -3],
                        ['kind' => 'tree', 'x' => -14, 'z' => -29],
                    ],
                ],
                'success_criteria' => [
                    'type' => 'waypoints',
                    'waypoints' => [
                        ['x' => 0, 'y' => 2, 'z' => -6, 'radius' => 1.5],
                        ['x' => 8, 'y' => 9.5, 'z' => -8, 'radius' => 1.8],
                    ],
                    'min_photos' => 1,
                    'photo_targets' => [
                        ['x' => -15, 'z' => -18, 'radius' => 5, 'label' => 'target-van'],
                    ],
                    'wash_required' => true,
                    'min_altitude' => 9.0,
                    'avoid_collisions' => true,
                    'max_time_seconds' => 150,
                    'landing_required' => true,
                ],
            ],
        ];
    }
}
