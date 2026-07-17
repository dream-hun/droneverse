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
}
