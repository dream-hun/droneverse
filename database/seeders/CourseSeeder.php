<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\Plan;
use App\Models\Course;
use Illuminate\Database\Seeder;

final class CourseSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * The Starter split (decision 1 in docs/pricing-implementation-plan.md):
     * the three beginner courses sit in the Starter tier so their briefings
     * are readable without paying, and Drone Basics' five missions are the
     * five a Starter pilot can actually fly. Precision Flight and Sensor
     * Flight are browsable but every mission in them states Pro for itself,
     * which is what the per-challenge override exists for. Delivery Ops and
     * City Operations are Pro outright and their missions simply inherit it.
     *
     * Finishing a whole free course is the upgrade moment, which is why the
     * five free missions are one complete course rather than five scattered
     * across three.
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
                    'required_plan' => $definition['required_plan'],
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
                        'required_plan' => $definition['challenges_required_plan'],
                        'starter_code' => $challenge['starter_code'],
                        'solution_code' => $challenge['solution_code'],
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
     * `required_plan` is the course's own tier; `challenges_required_plan` is
     * what its missions store, where null means "inherit the course".
     *
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
                'required_plan' => Plan::Starter->value,
                'challenges_required_plan' => null,
                'challenges' => $this->droneBasicsChallenges(),
            ],
            [
                'slug' => 'precision-flight',
                'title' => 'Precision Flight',
                'description' => 'Tight tolerances and exact flying: altitude control, slalom lines, low ceilings, and pinpoint landings.',
                'difficulty' => 'intermediate',
                'required_plan' => Plan::Starter->value,
                'challenges_required_plan' => Plan::Pro->value,
                'challenges' => $this->precisionFlightChallenges(),
            ],
            [
                'slug' => 'sensor-flight',
                'title' => 'Sensor Flight',
                'description' => 'Fly by feedback instead of fixed scripts: probe with the rangefinder, read position telemetry, and let the data steer the drone.',
                'difficulty' => 'advanced',
                'required_plan' => Plan::Starter->value,
                'challenges_required_plan' => Plan::Pro->value,
                'challenges' => $this->sensorFlightChallenges(),
            ],
            [
                'slug' => 'delivery-ops',
                'title' => 'Delivery Ops',
                'description' => 'Timed multi-stop delivery routes through a compact city block: plan clean lines, clear the rooftops, beat the clock.',
                'difficulty' => 'advanced',
                'required_plan' => Plan::Pro->value,
                'challenges_required_plan' => null,
                'challenges' => $this->deliveryOpsChallenges(),
            ],
            [
                'slug' => 'city-operations',
                'title' => 'City Operations',
                'description' => 'Full urban mission profiles in a living city block: sweep the streets with the object scanner, shoot survey photos that land in your photo log, run the drone wash, and graduate with a combined full-shift operation.',
                'difficulty' => 'advanced',
                'required_plan' => Plan::Pro->value,
                'challenges_required_plan' => null,
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
                'solution_code' => <<<'JS'
                    async function main(drone) {
                        // The default takeoff altitude is 1.5 m, comfortably above the
                        // 1 m minimum this flight plan grades on.
                        await drone.takeoff();

                        // Hold the hover long enough for the airframe to settle.
                        await drone.hover(2);

                        // `land()` descends straight down from wherever it is called,
                        // so the drone touches back down on the pad it left.
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
                'solution_code' => <<<'JS'
                    async function main(drone) {
                        await drone.takeoff();

                        // Waypoint 1 sits 5 m straight ahead of the pad, and the drone
                        // starts out facing it.
                        await drone.moveForward(5);

                        // Waypoint 2: a right turn (positive degrees) puts the nose on
                        // the next marker, 5 m away again.
                        await drone.turn(90);
                        await drone.moveForward(5);

                        // Waypoint 3: one more right turn closes the square back toward
                        // the start line.
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
                'solution_code' => <<<'JS'
                    async function main(drone) {
                        await drone.takeoff();

                        const goalZ = -18;

                        // Probe before every step and only fly into air the rangefinder
                        // says is clear. The crates are staggered either side of the
                        // center line, so the clean route is straight down the middle -
                        // but if one ever does sit in the lane, slide 3 m across, run
                        // past it, and rejoin the center line.
                        let position = await drone.getPosition();

                        while (position.z - goalZ > 0.5) {
                            const ahead = await drone.getDistanceAhead();

                            if (ahead > 3) {
                                await drone.moveForward(Math.min(3, position.z - goalZ));
                            } else {
                                console.log('Crate at', ahead, 'm - stepping around it.');

                                const lane = position.x <= 0 ? 3 : -3;

                                await drone.moveTo(position.x + lane, 1.5, position.z);
                                await drone.moveTo(position.x + lane, 1.5, position.z - 4);
                                await drone.moveTo(0, 1.5, position.z - 4);
                            }

                            position = await drone.getPosition();
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
                'solution_code' => <<<'JS'
                    async function main(drone) {
                        await drone.takeoff();

                        // Fly the gate centers as absolute points instead of
                        // dead-reckoning with turns: `moveTo` holds the line whatever
                        // the wind does, and a missed gate never counts.
                        const gates = [
                            [0, -6],
                            [4, -10],
                            [4, -16],
                            [0, -20],
                        ];

                        for (const [x, z] of gates) {
                            await drone.moveTo(x, 1.5, z);
                        }

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
                'solution_code' => <<<'JS'
                    async function main(drone) {
                        await drone.takeoff();

                        // Back-and-forth sweep through every corner marker, finishing on
                        // the center of the field. Absolute `moveTo` points mean the
                        // pattern never drifts, however long the sweep runs.
                        const sweep = [
                            [8, 0],
                            [0, 8],
                            [-8, 0],
                            [0, -8],
                            [0, 0],
                        ];

                        for (const [x, z] of sweep) {
                            await drone.moveTo(x, 1.5, z);
                            console.log('Sector swept:', x, z);
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
                'solution_code' => <<<'JS'
                    async function main(drone) {
                        await drone.takeoff();

                        // Rung 1: climb to 3 m, then fly the first 4 m leg.
                        // `setAltitude` changes height in place, and `moveForward`
                        // holds whatever height it is called at.
                        await drone.setAltitude(3);
                        await drone.moveForward(4);

                        // Rung 2: 5 m - this is also the pass that clears the 4 m
                        // ceiling the mission grades on.
                        await drone.setAltitude(5);
                        await drone.moveForward(4);

                        // Rung 3: back down to 1.5 m for the last leg and the landing.
                        await drone.setAltitude(1.5);
                        await drone.moveForward(4);

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
                'solution_code' => <<<'JS'
                    async function main(drone) {
                        await drone.takeoff();

                        // Right of pylon one, left of pylon two, right of pylon three,
                        // then straighten out onto the pad. 2.5 m of offset clears each
                        // post by far more than the drone's own width, and the straight
                        // lines between the markers never re-cross the center line
                        // where the pylons stand.
                        const line = [
                            [2.5, -5],
                            [-2.5, -10],
                            [2.5, -15],
                            [0, -19],
                        ];

                        for (const [x, z] of line) {
                            await drone.moveTo(x, 1.5, z);
                        }

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
                'solution_code' => <<<'JS'
                    async function main(drone) {
                        await drone.takeoff();

                        // Duck under the slabs once and stay there: every marker in the
                        // corridor sits at 1.2 m, and `moveForward` holds the altitude
                        // it is called at.
                        await drone.setAltitude(1.2);

                        await drone.moveForward(5);
                        await drone.moveForward(4);
                        await drone.moveForward(4);

                        // Clear of the last slab - run out to the landing pad.
                        await drone.moveForward(3);

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
                'solution_code' => <<<'JS'
                    async function main(drone) {
                        // Straight to 2 m: every marker on this approach sits at that
                        // height, so there is no altitude changing to do afterwards.
                        await drone.takeoff(2);

                        // Down the long leg of the L.
                        await drone.moveForward(6);

                        // Right at the corner, across the top of the L.
                        await drone.turn(90);
                        await drone.moveForward(4);

                        // Right again, onto the landing pad.
                        await drone.turn(90);
                        await drone.moveForward(4);

                        // The pad is only 0.8 m across, so touch down from the settled
                        // hover `moveForward` leaves behind rather than drifting in.
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
                'solution_code' => <<<'JS'
                    async function main(drone) {
                        await drone.takeoff();

                        // Creep forward on the rangefinder until the wall is 2.5 m off
                        // the nose. Where that happens depends on where the wall is,
                        // which is exactly the point - nothing here is hard-coded.
                        while ((await drone.getDistanceAhead()) > 2.5) {
                            await drone.moveForward(1);
                        }

                        const contact = await drone.getPosition();
                        console.log('Wall contact at z =', Math.round(contact.z * 10) / 10);

                        // The wall runs from x = -5 to x = +5, so slide east well past
                        // its edge at the height we stopped at...
                        await drone.moveTo(6.5, 1.5, contact.z);

                        // ...then push through the gap to the marker behind it.
                        await drone.moveTo(6.5, 1.5, -12);

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
                'solution_code' => <<<'JS'
                    async function main(drone) {
                        await drone.takeoff();

                        // Run the canyon on the rangefinder: step forward while there is
                        // open air ahead, and stop once the end wall closes inside 2 m.
                        // The walls are only ~3 m either side, so hold the center line.
                        while ((await drone.getDistanceAhead()) > 2) {
                            await drone.moveForward(1);
                        }

                        // Dead-ended. The right-hand wall stops short of the end wall,
                        // so the way out is due east of where the canyon runs out.
                        await drone.turn(90);
                        console.log('Exit reads', await drone.getDistanceAhead(), 'm of open air.');

                        await drone.moveForward(6);

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
                'solution_code' => <<<'JS'
                    async function main(drone) {
                        await drone.takeoff();

                        // Remember the pad before flying anywhere - this is what makes
                        // the last leg a homing run instead of a guess.
                        const home = await drone.getPosition();
                        console.log('Home pad at', home.x, home.z);

                        // Leg 1: 6 m east of wherever we are right now.
                        let here = await drone.getPosition();
                        await drone.moveTo(here.x + 6, 1.5, here.z);

                        // Leg 2: 6 m south of wherever that put us.
                        here = await drone.getPosition();
                        await drone.moveTo(here.x, 1.5, here.z - 6);

                        // Leg 3: straight back to the remembered pad.
                        await drone.moveTo(home.x, 1.5, home.z);

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
                'solution_code' => <<<'JS'
                    async function main(drone) {
                        await drone.takeoff();

                        // Run the open center lane past the west building, then cut in
                        // behind it for the first drop.
                        await drone.moveTo(0, 1.5, -8);
                        await drone.moveTo(-4, 1.5, -8);

                        // Drop two is behind the building across the street. Square the
                        // route off instead of cutting the diagonal - the straight line
                        // between the two pads shaves the east building's corner.
                        await drone.moveTo(-4, 1.5, -12);
                        await drone.moveTo(4, 1.5, -12);

                        // Depot pad at the end of the block.
                        await drone.moveTo(0, 1.5, -15);

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
                'solution_code' => <<<'JS'
                    async function main(drone) {
                        await drone.takeoff();

                        // Climb before crossing, not while crossing: the tower is 6 m
                        // and the drop marker sits at 7 m, a metre clear of the roof.
                        await drone.setAltitude(7);
                        await drone.moveTo(0, 7, -8);

                        // Hold over the rooftop marker for the drop.
                        await drone.hover(1);

                        // Clear the far face at altitude first, then descend on the
                        // street side. Descending over the roof would just land on it.
                        await drone.moveTo(0, 7, -13);
                        await drone.moveTo(0, 1.5, -13);

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
                'solution_code' => <<<'JS'
                    async function main(drone) {
                        await drone.takeoff();

                        // The columns all stand on the center line, and every leg here
                        // is a diagonal that crosses it a long way from any of them -
                        // so the whole route can be flown flat out.
                        await drone.setSpeed(8);

                        const stops = [
                            [-6, -4],
                            [6, -8],
                            [-6, -12],
                            [0, -16],
                        ];

                        for (const [x, z] of stops) {
                            await drone.moveTo(x, 1.5, z);
                            console.log('Delivered:', x, z);
                        }

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
                'solution_code' => <<<'JS'
                    async function main(drone) {
                        await drone.takeoff(2);

                        // Stop 1 - street pickup in the avenue mouth. The towers leave a
                        // clear lane between x = -4 and x = +4, so the center line is safe.
                        await drone.moveTo(0, 2, -6);

                        // Stop 2 - rooftop drop on the low NE block. Climb clear of the
                        // 8 m roof BEFORE sliding over it; 9 m also banks the altitude
                        // requirement the mission grades on.
                        await drone.setAltitude(9);
                        await drone.moveTo(8, 9, -8);
                        await drone.hover(1);

                        // Stop 3 - curb delivery on the east service street. Stay high
                        // all the way out and past the parked service van, then drop
                        // straight down onto the marker on a clear column.
                        await drone.moveTo(14, 9, -18);
                        await drone.moveTo(14, 1.6, -18);

                        // Home - back to the corridor between the tower rows at z = -18,
                        // which is south of the north pair and north of the south pair,
                        // then straight down the avenue to the depot.
                        await drone.moveTo(0, 1.6, -18);
                        await drone.moveTo(0, 1.5, -30);

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
                'solution_code' => <<<'JS'
                    async function main(drone) {
                        await drone.takeoff(2);

                        // The scanner sees through walls: every contact within 30 m,
                        // nearest first, each one { kind, label, x, y, z, distance,
                        // bearingDeg }. Nothing about the van is hard-coded below.
                        const contacts = await drone.scan(30);
                        console.log('Contacts on scope:', contacts.length);

                        const van = contacts.find((contact) => contact.label === 'delivery-van');

                        if (!van) {
                            console.error('No delivery van in range - aborting.');
                            await drone.land();

                            return;
                        }

                        console.log('Van located at', van.x, van.z, '-', van.distance, 'm out.');

                        // The city blocks top out at 7 m, so cross the block at 9 m and
                        // only descend once the drone is over open street.
                        await drone.setAltitude(9);
                        await drone.moveTo(van.x + 4, 9, van.z);
                        await drone.moveTo(van.x + 4, 2.4, van.z);

                        // Standing 4 m east of the van, well inside the 5 m the photo
                        // has to be shot from. Swing the nose west onto it and fire.
                        await drone.turn(-90);
                        await drone.takePhoto('delivery-van');

                        // Home the same way: back up over the rooftops, then down.
                        await drone.setAltitude(9);
                        await drone.moveTo(0, 9, 0);
                        await drone.moveTo(0, 2, 0);

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
                'solution_code' => <<<'JS'
                    async function main(drone) {
                        await drone.takeoff();

                        // Survey the crate yard at 3 m. The markers sit at 2 m, right on
                        // top of the crates - their capture rings are 1.5 m across, so
                        // 3 m still scores them while flying a metre above the lids.
                        await drone.setAltitude(3);

                        // Approach down the open lane first, then west along z = -14:
                        // the site office sits between the pad and the yard, and this
                        // route goes around it rather than over it.
                        await drone.moveTo(0, 3, -14);
                        await drone.moveTo(-8, 3, -14);
                        await drone.moveTo(-4, 3, -19);

                        // Line up outside the tunnel's south mouth. The tunnel runs
                        // north-south at x = 8 with mouths at z = -14 and z = -6.
                        await drone.moveTo(8, 3, -16);
                        await drone.setAltitude(1.6);

                        // Straight through: both wash beams have to see the drone pass,
                        // and the opening is 4.5 m wide by 3.5 m tall, so keep it dead
                        // center and low. Stop short of the parked cars beyond the exit.
                        await drone.moveTo(8, 1.6, -5);

                        // Clean airframe - climb over the street furniture and go home.
                        await drone.setAltitude(6);
                        await drone.moveTo(0, 6, 0);

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
                'solution_code' => <<<'JS'
                    async function main(drone) {
                        await drone.takeoff(2);

                        // Transit at 9.5 m for the whole run. Nothing in the city is
                        // taller than the 8 m HQ tower, so every crossing is clear, and
                        // it banks the 9 m ceiling the mission grades on up front.
                        const transit = 9.5;

                        await drone.setAltitude(transit);

                        // Shot 1 - the plaza fountain. Hold 2 m north of it, facing
                        // south (the launch heading), so the fountain fills the frame
                        // and the drone stays inside the beacon ring.
                        await drone.moveTo(-10, transit, -6);
                        await drone.setAltitude(2.5);
                        await drone.takePhoto('fountain');

                        // Shot 2 - the supply yard. Same trick: stand 2 m short of the
                        // cache and shoot it head-on. The crate is 2 m tall, so hold
                        // 3.5 m rather than dropping to the beacon's own height.
                        await drone.setAltitude(transit);
                        await drone.moveTo(12, transit, -12);
                        await drone.setAltitude(3.5);
                        await drone.takePhoto('supply-cache');

                        // Shot 3 - the HQ rooftop. The roof tops out at 8 m and the ring
                        // is tight, so this one is shot from directly overhead at
                        // transit height.
                        await drone.setAltitude(transit);
                        await drone.moveTo(0, transit, -26);
                        await drone.takePhoto('hq-rooftop');

                        // Home down the middle, still above the skyline.
                        await drone.moveTo(0, transit, 0);

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
                'solution_code' => <<<'JS'
                    async function main(drone) {
                        await drone.takeoff(2);

                        // The four towers stand in two rows, leaving two open corridors:
                        // the avenue at x = 0 and the cross street at z = -14. Every leg
                        // below stays in one of them or flies above the low NE block.
                        const transit = 9.5;

                        // 1. Street pickup in the avenue mouth.
                        await drone.moveTo(0, 2, -6);

                        // 2. Rooftop drop on the NE block. It is 8 m tall, so climb
                        // first - which also banks the 9 m ceiling the run is graded on.
                        await drone.setAltitude(transit);
                        await drone.moveTo(8, transit, -8);
                        await drone.hover(1);

                        // 3. Find the van. The scanner reaches 40 m and reports labels,
                        // so nothing about its position is hard-coded.
                        const contacts = await drone.scan(40);
                        const van = contacts.find((contact) => contact.label === 'target-van');

                        if (!van) {
                            console.error('No target van on scope - aborting.');
                            await drone.land();

                            return;
                        }

                        console.log('Target van at', van.x, van.z);

                        // Cross to it through the corridor - a straight line from the
                        // rooftop would fly into the 14 m NW tower.
                        await drone.moveTo(0, transit, -14);
                        await drone.moveTo(van.x, transit, -14);

                        // Drop to camera height 4 m north of the van, facing south onto
                        // it - well inside the 5 m the photo has to be shot from.
                        await drone.moveTo(van.x, 3, van.z + 4);
                        await drone.takePhoto('target-van');

                        // 4. Wash cycle. Back along the corridor to the east service
                        // street, then down the column between the parked car and the
                        // tunnel's north mouth.
                        await drone.setAltitude(transit);
                        await drone.moveTo(16, transit, -14);
                        await drone.moveTo(16, transit, -21);
                        await drone.setAltitude(1.6);

                        // Straight through the tunnel: both beams have to see the drone.
                        await drone.moveTo(16, 1.6, -31);

                        // 5. Home to the south depot, running west below the tower row.
                        await drone.moveTo(0, 1.6, -30);

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
