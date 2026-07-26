<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Challenge;
use App\Models\Course;
use Database\Seeders\CourseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class CourseSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeding_twice_creates_no_duplicates(): void
    {
        $this->seed(CourseSeeder::class);

        $this->assertSame(5, Course::count());
        $this->assertSame(20, Challenge::count());

        $this->seed(CourseSeeder::class);

        $this->assertSame(5, Course::count());
        $this->assertSame(20, Challenge::count());
    }

    public function test_seeded_catalog_is_published_and_ordered(): void
    {
        $this->seed(CourseSeeder::class);

        $this->assertSame(
            ['drone-basics', 'precision-flight', 'sensor-flight', 'delivery-ops', 'city-operations'],
            Course::query()->orderBy('order')->pluck('slug')->all(),
        );

        $this->assertTrue(Course::query()->where('is_published', false)->doesntExist());
        $this->assertTrue(Challenge::query()->where('is_published', false)->doesntExist());

        Course::query()->withCount('challenges')->get()->each(function (Course $course): void {
            $this->assertGreaterThanOrEqual(1, $course->challenges_count, "course {$course->slug} has no challenges");
        });
    }

    public function test_seeded_challenges_have_playable_environments(): void
    {
        $this->seed(CourseSeeder::class);

        Challenge::query()->get()->each(function (Challenge $challenge): void {
            $slug = $challenge->slug;
            $environment = $challenge->environment;
            $criteria = $challenge->success_criteria;

            $this->assertStringContainsString('async function main(drone)', $challenge->starter_code, $slug);
            $this->assertSame(100, $challenge->max_score, $slug);

            foreach (['x', 'y', 'z', 'yaw'] as $key) {
                $this->assertArrayHasKey($key, $environment['start'], "{$slug}: start.{$key}");
            }

            $bounds = $environment['bounds'];

            foreach (['width', 'depth', 'height'] as $key) {
                $this->assertArrayHasKey($key, $bounds, "{$slug}: bounds.{$key}");
                $this->assertGreaterThan(0, $bounds[$key], "{$slug}: bounds.{$key}");
            }

            $this->assertContains($criteria['type'], ['waypoints', 'gates'], $slug);
            $this->assertIsBool($criteria['avoid_collisions'], $slug);
            $this->assertIsBool($criteria['landing_required'], $slug);
            $this->assertIsArray($criteria['waypoints'], $slug);
            $this->assertGreaterThanOrEqual(10, $criteria['max_time_seconds'], "{$slug}: max_time_seconds");

            if (array_key_exists('min_altitude', $criteria)) {
                $this->assertGreaterThan(0, $criteria['min_altitude'], "{$slug}: min_altitude");
                $this->assertLessThanOrEqual($bounds['height'], $criteria['min_altitude'], "{$slug}: min_altitude exceeds bounds");
            }

            $this->assertGreaterThan(0, $environment['goal']['radius'], "{$slug}: goal radius");
            $this->assertPointWithinBounds($environment['goal'] + ['y' => 1], $bounds, "{$slug}: goal");

            foreach ($criteria['waypoints'] as $index => $waypoint) {
                $this->assertGreaterThanOrEqual(0.5, $waypoint['radius'], "{$slug}: criteria waypoint {$index} radius too small to hit");
                $this->assertPointWithinBounds($waypoint, $bounds, "{$slug}: criteria waypoint {$index}");
            }

            foreach ($environment['waypoints'] as $index => $waypoint) {
                $this->assertPointWithinBounds($waypoint, $bounds, "{$slug}: environment waypoint {$index}");
            }

            foreach ($environment['obstacles'] as $index => $obstacle) {
                $this->assertPointWithinBounds($obstacle, $bounds, "{$slug}: obstacle {$index}");
            }

            foreach ($environment['props'] ?? [] as $index => $prop) {
                $this->assertContains($prop['kind'], ['car', 'van', 'tree'], "{$slug}: prop {$index} kind");
                $this->assertPointWithinBounds($prop + ['y' => 1], $bounds, "{$slug}: prop {$index}");
            }

            if (array_key_exists('carwash', $environment)) {
                $this->assertPointWithinBounds($environment['carwash'] + ['y' => 1], $bounds, "{$slug}: carwash");
            }

            if (array_key_exists('photo_targets', $criteria)) {
                $this->assertNotEmpty($criteria['photo_targets'], "{$slug}: photo_targets empty");

                foreach ($criteria['photo_targets'] as $index => $target) {
                    $this->assertGreaterThanOrEqual(1, $target['radius'], "{$slug}: photo target {$index} radius too small to hit");
                    $this->assertPointWithinBounds($target + ['y' => 1], $bounds, "{$slug}: photo target {$index}");
                }
            }

            if (array_key_exists('min_photos', $criteria)) {
                $this->assertGreaterThanOrEqual(1, $criteria['min_photos'], "{$slug}: min_photos");
                $this->assertLessThanOrEqual(10, $criteria['min_photos'], "{$slug}: min_photos unreasonably high");
            }

            if (! empty($criteria['wash_required'])) {
                $this->assertArrayHasKey('carwash', $environment, "{$slug}: wash required but no carwash in the environment");
            }
        });
    }

    public function test_every_seeded_challenge_ships_a_reference_solution(): void
    {
        $this->seed(CourseSeeder::class);

        Challenge::query()->get()->each(function (Challenge $challenge): void {
            $slug = $challenge->slug;
            $solution = $challenge->solution_code;
            $criteria = $challenge->success_criteria;

            $this->assertNotNull($solution, "{$slug}: no reference solution");
            $this->assertStringContainsString('async function main(drone)', $solution, $slug);
            $this->assertNotSame(
                $challenge->starter_code,
                $solution,
                "{$slug}: the solution is just the starter code",
            );

            if ($criteria['landing_required']) {
                $this->assertStringContainsString('drone.land()', $solution, "{$slug}: never lands");
            }

            // A photo mission's solution has to actually take the photos the
            // grader counts, otherwise it cannot score.
            $minPhotos = $criteria['min_photos'] ?? 0;

            if ($minPhotos > 0) {
                $this->assertGreaterThanOrEqual(
                    $minPhotos,
                    mb_substr_count($solution, 'drone.takePhoto'),
                    "{$slug}: fewer takePhoto calls than the mission requires",
                );
            }
        });
    }

    public function test_city_operations_exercises_the_full_mission_toolkit(): void
    {
        $this->seed(CourseSeeder::class);

        $course = Course::query()->where('slug', 'city-operations')->sole();
        $challenges = $course->challenges()->orderBy('order')->get()->keyBy('slug');

        $this->assertSame(
            ['downtown-gauntlet', 'street-sweep', 'wash-and-return', 'skyline-survey', 'full-shift'],
            $challenges->keys()->all(),
        );

        $sweep = $challenges['street-sweep'];
        $this->assertNotEmpty($sweep->success_criteria['photo_targets']);
        $this->assertStringContainsString('drone.scan', $sweep->starter_code);
        $this->assertTrue(
            collect($sweep->environment['props'])->contains(
                fn (array $prop): bool => ($prop['label'] ?? null) === 'delivery-van',
            ),
            'street-sweep needs the delivery-van prop its scanner mission hunts for',
        );

        $wash = $challenges['wash-and-return'];
        $this->assertTrue($wash->success_criteria['wash_required']);
        $this->assertArrayHasKey('carwash', $wash->environment);

        $survey = $challenges['skyline-survey'];
        $this->assertSame(3, $survey->success_criteria['min_photos']);
        $this->assertCount(3, $survey->success_criteria['photo_targets']);
        $this->assertStringContainsString('drone.takePhoto', $survey->starter_code);

        $shift = $challenges['full-shift'];
        $this->assertTrue($shift->success_criteria['wash_required']);
        $this->assertNotEmpty($shift->success_criteria['photo_targets']);
        $this->assertNotEmpty($shift->success_criteria['waypoints']);
        $this->assertArrayHasKey('carwash', $shift->environment);
    }

    /**
     * The ground plane is centered on the origin, so anything playable must sit
     * inside half the width/depth and below the height ceiling — a goal or
     * waypoint outside the pad renders (and lands the drone) off the world.
     *
     * @param  array<string, mixed>  $point
     * @param  array<string, mixed>  $bounds
     */
    private function assertPointWithinBounds(array $point, array $bounds, string $context): void
    {
        $this->assertLessThanOrEqual($bounds['width'] / 2, abs($point['x']), "{$context}: x outside ground plane");
        $this->assertLessThanOrEqual($bounds['depth'] / 2, abs($point['z']), "{$context}: z outside ground plane");
        $this->assertGreaterThan(0, $point['y'], "{$context}: y below ground");
        $this->assertLessThanOrEqual($bounds['height'], $point['y'], "{$context}: y above bounds");
    }
}
