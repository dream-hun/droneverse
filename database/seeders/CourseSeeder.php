<?php

namespace Database\Seeders;

use App\Models\Course;
use Illuminate\Database\Seeder;

class CourseSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $course = Course::query()->updateOrCreate(
            ['slug' => 'drone-basics'],
            [
                'title' => 'Drone Basics',
                'description' => 'Learn to pilot a drone with code: takeoff and landing, waypoint navigation, obstacle avoidance, gate racing, and search patterns.',
                'difficulty' => 'beginner',
                'order' => 0,
                'is_published' => true,
            ],
        );

        foreach ($this->challenges() as $order => $challenge) {
            $course->challenges()->updateOrCreate(
                ['slug' => $challenge['slug']],
                [
                    'title' => $challenge['title'],
                    'briefing' => $challenge['briefing'],
                    'order' => $order,
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

    /**
     * @return array<int, array<string, mixed>>
     */
    private function challenges(): array
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
                    'bounds' => ['width' => 20, 'depth' => 28, 'height' => 10],
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
                    'bounds' => ['width' => 30, 'depth' => 30, 'height' => 10],
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
                'briefing' => "Sweep the whole field to find every marker: visit all four corners, then the center, before landing. A boustrophedon (back-and-forth) pattern covers ground efficiently.",
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
}
