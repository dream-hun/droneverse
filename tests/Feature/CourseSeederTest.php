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

        $this->assertSame(4, Course::count());
        $this->assertSame(15, Challenge::count());

        $this->seed(CourseSeeder::class);

        $this->assertSame(4, Course::count());
        $this->assertSame(15, Challenge::count());
    }

    public function test_seeded_catalog_is_published_and_ordered(): void
    {
        $this->seed(CourseSeeder::class);

        $this->assertSame(
            ['drone-basics', 'precision-flight', 'sensor-flight', 'delivery-ops'],
            Course::query()->orderBy('order')->pluck('slug')->all(),
        );

        $this->assertTrue(Course::query()->where('is_published', false)->doesntExist());
        $this->assertTrue(Challenge::query()->where('is_published', false)->doesntExist());

        Course::query()->withCount('challenges')->get()->each(function (Course $course): void {
            $this->assertGreaterThanOrEqual(3, $course->challenges_count, "course {$course->slug} has too few challenges");
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
        });
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
