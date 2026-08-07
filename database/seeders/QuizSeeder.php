<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\QuizQuestionType;
use App\Models\Course;
use App\Models\Quiz;
use Illuminate\Database\Seeder;

final class QuizSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * One knowledge check per beginner course, covering what that course's
     * missions actually teach. Every question is about the real drone API in
     * resources/js/lib/simulator/worker.ts — a quiz that tests invented
     * commands teaches pilots something that will not fly.
     *
     * No quiz states a `required_plan` of its own, so each inherits its
     * course's tier. That is deliberate for Precision Flight in particular:
     * its *missions* are Pro while the course is Starter, and the quiz
     * follows the course rather than the missions. Reading the briefings and
     * answering questions about them is the free half of that course, and it
     * is the half that argues for the upgrade.
     */
    public function run(): void
    {
        foreach ($this->quizzes() as $definition) {
            $course = Course::query()->where('slug', $definition['course_slug'])->first();

            // A quiz without its course is not an error worth stopping the
            // seeder for — CourseSeeder may simply not have run, or the
            // course may have been retired from the catalog since.
            if (! $course instanceof Course) {
                continue;
            }

            $quiz = Quiz::query()->updateOrCreate(
                ['course_id' => $course->id, 'slug' => $definition['slug']],
                [
                    'title' => $definition['title'],
                    'description' => $definition['description'],
                    'order' => 0,
                    'required_plan' => null,
                    'pass_percentage' => $definition['pass_percentage'],
                    'is_published' => true,
                ],
            );

            /*
             * Questions are replaced wholesale rather than merged. They carry
             * no natural key to match on — an edited prompt is not a new
             * question, and re-ordering two is not an edit to either — and
             * nothing user-owned points at them: `quiz_attempts` records
             * counts against the quiz, not per-option answers, precisely so
             * re-authoring a quiz cannot orphan a pilot's history. Options
             * go with their question through the cascade.
             */
            $quiz->questions()->delete();

            foreach ($definition['questions'] as $order => $question) {
                $created = $quiz->questions()->create([
                    'prompt' => $question['prompt'],
                    'type' => $question['type'],
                    'explanation' => $question['explanation'],
                    'order' => $order,
                ]);

                foreach ($question['options'] as $optionOrder => $option) {
                    $created->options()->create([
                        'label' => $option[0],
                        'is_correct' => $option[1],
                        'order' => $optionOrder,
                    ]);
                }
            }
        }
    }

    /**
     * Each option is `[label, isCorrect]`.
     *
     * @return array<int, array<string, mixed>>
     */
    private function quizzes(): array
    {
        return [
            [
                'course_slug' => 'drone-basics',
                'slug' => 'flight-fundamentals',
                'title' => 'Flight Fundamentals',
                'description' => 'Check what you learned flying Drone Basics: how a program controls the drone, how the coordinate system works, and what a mission scores you on.',
                'pass_percentage' => 70,
                'questions' => [
                    [
                        'prompt' => 'What must your program define for the simulator to fly anything?',
                        'type' => QuizQuestionType::Single,
                        'explanation' => 'The worker calls `main(drone)` after evaluating your code and throws if no function by that name exists. It is awaited, so it must be async.',
                        'options' => [
                            ['An async function named `main` that takes the drone as its argument', true],
                            ['A class named `Drone` with a `fly()` method', false],
                            ['A top-level `await drone.takeoff()` call', false],
                            ['A function named `run` returning a list of commands', false],
                        ],
                    ],
                    [
                        'prompt' => 'Why does every drone command need `await`?',
                        'type' => QuizQuestionType::Single,
                        'explanation' => 'Each command returns a promise that settles when the manoeuvre finishes. Without `await` the next command is issued immediately, so the drone is told to do two things at once.',
                        'options' => [
                            ['Each command returns a promise that resolves when the manoeuvre completes', true],
                            ['`await` is what sends the command to the simulator', false],
                            ['It slows the drone to a safe speed', false],
                            ['It is optional — the simulator queues commands either way', false],
                        ],
                    ],
                    [
                        'prompt' => 'In `drone.moveTo(x, y, z)`, which argument is altitude?',
                        'type' => QuizQuestionType::Single,
                        'explanation' => 'The simulator uses a Y-up coordinate system: `x` and `z` move across the ground, `y` is height above it.',
                        'options' => [
                            ['`y`', true],
                            ['`x`', false],
                            ['`z`', false],
                            ['Whichever is largest', false],
                        ],
                    ],
                    [
                        'prompt' => 'Which of these will cost you points on a mission that requires a landing?',
                        'type' => QuizQuestionType::Multiple,
                        'explanation' => 'Collisions and an unfinished landing both count against the run. Hovering costs nothing by itself, but running past the mission time limit does.',
                        'options' => [
                            ['Hitting an obstacle', true],
                            ['Ending the run still in the air', true],
                            ['Running past the mission time limit', true],
                            ['Hovering in place partway through', false],
                        ],
                    ],
                    [
                        'prompt' => 'Which call reads how far it is to the nearest obstacle in front of the drone?',
                        'type' => QuizQuestionType::Single,
                        'explanation' => '`getDistanceAhead()` measures straight ahead. `scan()` is the wider sweep that reports labelled objects around the drone.',
                        'options' => [
                            ['`await drone.getDistanceAhead()`', true],
                            ['`await drone.getPosition()`', false],
                            ['`await drone.getAltitude()`', false],
                            ['`await drone.moveForward(0)`', false],
                        ],
                    ],
                ],
            ],
            [
                'course_slug' => 'precision-flight',
                'slug' => 'precision-and-control',
                'title' => 'Precision and Control',
                'description' => 'Check your grasp of exact flying: holding an altitude, controlling speed, and getting a landing on the mark.',
                'pass_percentage' => 70,
                'questions' => [
                    [
                        'prompt' => 'Which call changes height without moving the drone across the ground?',
                        'type' => QuizQuestionType::Single,
                        'explanation' => '`setAltitude()` changes only `y`. `moveTo()` would need the current `x` and `z` passed back in to have the same effect.',
                        'options' => [
                            ['`await drone.setAltitude(3)`', true],
                            ['`await drone.moveForward(3)`', false],
                            ['`await drone.turn(90)`', false],
                            ['`await drone.takeoff(3)` again', false],
                        ],
                    ],
                    [
                        'prompt' => 'You keep overshooting a tight waypoint. What is the most direct fix?',
                        'type' => QuizQuestionType::Single,
                        'explanation' => 'Lower the speed before the tight section with `setSpeed()`. A slower approach settles inside a small radius that a fast one flies straight through.',
                        'options' => [
                            ['Call `drone.setSpeed()` with a lower value before the approach', true],
                            ['Add more `moveTo()` calls to the same point', false],
                            ['Increase the altitude so the waypoint is easier to reach', false],
                            ['Remove the `await` so the command finishes sooner', false],
                        ],
                    ],
                    [
                        'prompt' => 'What does `drone.turn(degrees)` change?',
                        'type' => QuizQuestionType::Single,
                        'explanation' => 'Turning changes heading only. The drone stays where it is; the next `moveForward()` is what takes it somewhere new.',
                        'options' => [
                            ['The direction the drone faces, without moving it', true],
                            ['The drone position along a circular arc', false],
                            ['The angle of the camera only', false],
                            ['The direction of the next `moveTo()` call', false],
                        ],
                    ],
                    [
                        'prompt' => 'Which are true of a mission with a low ceiling?',
                        'type' => QuizQuestionType::Multiple,
                        'explanation' => 'A ceiling is an obstacle like any other: climbing into it is a collision. Taking off to a stated altitude keeps the first climb under control.',
                        'options' => [
                            ['Climbing into the ceiling counts as a collision', true],
                            ['Passing an altitude to `takeoff()` keeps the first climb bounded', true],
                            ['The ceiling stops the drone harmlessly', false],
                            ['Altitude limits are only advisory', false],
                        ],
                    ],
                ],
            ],
            [
                'course_slug' => 'sensor-flight',
                'slug' => 'sensing-and-navigation',
                'title' => 'Sensing and Navigation',
                'description' => 'Check how well you can fly on instruments: reading the world with scans and distance checks instead of hard-coded coordinates.',
                'pass_percentage' => 70,
                'questions' => [
                    [
                        'prompt' => 'What does `await drone.scan()` give you that `getDistanceAhead()` does not?',
                        'type' => QuizQuestionType::Single,
                        'explanation' => 'A scan sweeps the area and reports the objects around the drone, including the callsigns authored on them. A distance check reports one number, straight ahead.',
                        'options' => [
                            ['The objects around the drone, with their labels', true],
                            ['A photograph of what is ahead', false],
                            ['The remaining battery', false],
                            ['The drone own position', false],
                        ],
                    ],
                    [
                        'prompt' => 'Why is flying from sensor readings better than hard-coding coordinates?',
                        'type' => QuizQuestionType::Single,
                        'explanation' => 'A program that measures adapts when the mission does. One that memorizes a path solves that path only, which is what these missions are built to expose.',
                        'options' => [
                            ['The program still works when the layout is not what you memorized', true],
                            ['Sensor calls score extra points', false],
                            ['Hard-coded coordinates are rejected by the grader', false],
                            ['Sensors make the drone fly faster', false],
                        ],
                    ],
                    [
                        'prompt' => 'You want to stop one metre short of a wall ahead. Which loop shape is right?',
                        'type' => QuizQuestionType::Single,
                        'explanation' => 'Re-read the distance each time round the loop. Reading once before the loop tests a measurement that goes stale the moment the drone moves.',
                        'options' => [
                            ['Read the distance inside the loop and move a short step while it is over one metre', true],
                            ['Read the distance once, then move that far in a single step', false],
                            ['Move forward in steps and check the distance after the loop ends', false],
                            ['Call `getDistanceAhead()` without `await` in a tight loop', false],
                        ],
                    ],
                    [
                        'prompt' => 'Which are true about `drone.takePhoto()`?',
                        'type' => QuizQuestionType::Multiple,
                        'explanation' => 'A photo is taken from where the drone is, and a label helps you tell shots apart afterwards. Missions that ask for photos check where each was taken from, so position still matters.',
                        'options' => [
                            ['It captures from the drone current position and heading', true],
                            ['It accepts an optional label', true],
                            ['It can be called before takeoff to capture the whole map', false],
                            ['It satisfies a photo objective from anywhere on the map', false],
                        ],
                    ],
                ],
            ],
        ];
    }
}
