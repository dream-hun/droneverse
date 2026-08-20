<?php

declare(strict_types=1);

use App\Enums\Plan;
use App\Models\Challenge;
use App\Models\Course;
use Database\Seeders\CourseSeeder;

test('seeding twice creates no duplicates', function (): void {
    $this->seed(CourseSeeder::class);

    $this->assertSame(5, Course::query()->count());
    $this->assertSame(20, Challenge::query()->count());

    $this->seed(CourseSeeder::class);

    $this->assertSame(5, Course::query()->count());
    $this->assertSame(20, Challenge::query()->count());
});

test('seeded catalog is published and ordered', function (): void {
    $this->seed(CourseSeeder::class);

    $this->assertSame(
        ['drone-basics', 'precision-flight', 'sensor-flight', 'delivery-ops', 'city-operations'],
        Course::query()->orderBy('order')->pluck('slug')->all(),
    );

    $this->assertTrue(Course::query()->where('is_published', false)->doesntExist());
    $this->assertTrue(Challenge::query()->where('is_published', false)->doesntExist());

    Course::query()->withCount('challenges')->get()->each(function (Course $course): void {
        $this->assertGreaterThanOrEqual(1, $course->challenges_count, sprintf('course %s has no challenges', $course->slug));
    });
});

test('seeded challenges have playable environments', function (): void {
    $this->seed(CourseSeeder::class);

    Challenge::query()->get()->each(function (Challenge $challenge): void {
        $slug = $challenge->slug;
        $environment = $challenge->environment;
        $criteria = $challenge->success_criteria;

        $this->assertStringContainsString('async function main(drone)', $challenge->starter_code, $slug);
        $this->assertSame(100, $challenge->max_score, $slug);

        foreach (['x', 'y', 'z', 'yaw'] as $key) {
            $this->assertArrayHasKey($key, $environment['start'], sprintf('%s: start.%s', $slug, $key));
        }

        $bounds = $environment['bounds'];

        foreach (['width', 'depth', 'height'] as $key) {
            $this->assertArrayHasKey($key, $bounds, sprintf('%s: bounds.%s', $slug, $key));
            $this->assertGreaterThan(0, $bounds[$key], sprintf('%s: bounds.%s', $slug, $key));
        }

        $this->assertContains($criteria['type'], ['waypoints', 'gates'], $slug);
        $this->assertIsBool($criteria['avoid_collisions'], $slug);
        $this->assertIsBool($criteria['landing_required'], $slug);
        $this->assertIsArray($criteria['waypoints'], $slug);
        $this->assertGreaterThanOrEqual(10, $criteria['max_time_seconds'], $slug.': max_time_seconds');

        if (array_key_exists('min_altitude', $criteria)) {
            $this->assertGreaterThan(0, $criteria['min_altitude'], $slug.': min_altitude');
            $this->assertLessThanOrEqual($bounds['height'], $criteria['min_altitude'], $slug.': min_altitude exceeds bounds');
        }

        $this->assertGreaterThan(0, $environment['goal']['radius'], $slug.': goal radius');
        assertPointWithinBounds($environment['goal'] + ['y' => 1], $bounds, $slug.': goal');

        foreach ($criteria['waypoints'] as $index => $waypoint) {
            $this->assertGreaterThanOrEqual(0.5, $waypoint['radius'], sprintf('%s: criteria waypoint %s radius too small to hit', $slug, $index));
            assertPointWithinBounds($waypoint, $bounds, sprintf('%s: criteria waypoint %s', $slug, $index));
        }

        foreach ($environment['waypoints'] as $index => $waypoint) {
            assertPointWithinBounds($waypoint, $bounds, sprintf('%s: environment waypoint %s', $slug, $index));
        }

        foreach ($environment['obstacles'] as $index => $obstacle) {
            assertPointWithinBounds($obstacle, $bounds, sprintf('%s: obstacle %s', $slug, $index));
        }

        foreach ($environment['props'] ?? [] as $index => $prop) {
            $this->assertContains($prop['kind'], ['car', 'van', 'tree'], sprintf('%s: prop %s kind', $slug, $index));
            assertPointWithinBounds($prop + ['y' => 1], $bounds, sprintf('%s: prop %s', $slug, $index));
        }

        if (array_key_exists('carwash', $environment)) {
            assertPointWithinBounds($environment['carwash'] + ['y' => 1], $bounds, $slug.': carwash');
        }

        if (array_key_exists('photo_targets', $criteria)) {
            $this->assertNotEmpty($criteria['photo_targets'], $slug.': photo_targets empty');

            foreach ($criteria['photo_targets'] as $index => $target) {
                $this->assertGreaterThanOrEqual(1, $target['radius'], sprintf('%s: photo target %s radius too small to hit', $slug, $index));
                assertPointWithinBounds($target + ['y' => 1], $bounds, sprintf('%s: photo target %s', $slug, $index));
            }
        }

        if (array_key_exists('min_photos', $criteria)) {
            $this->assertGreaterThanOrEqual(1, $criteria['min_photos'], $slug.': min_photos');
            $this->assertLessThanOrEqual(10, $criteria['min_photos'], $slug.': min_photos unreasonably high');
        }

        if (! empty($criteria['wash_required'])) {
            $this->assertArrayHasKey('carwash', $environment, $slug.': wash required but no carwash in the environment');
        }
    });
});

test('every seeded challenge ships a reference solution', function (): void {
    $this->seed(CourseSeeder::class);

    Challenge::query()->get()->each(function (Challenge $challenge): void {
        $slug = $challenge->slug;
        $solution = $challenge->solution_code;
        $criteria = $challenge->success_criteria;

        $this->assertNotNull($solution, $slug.': no reference solution');
        $this->assertStringContainsString('async function main(drone)', $solution, $slug);
        $this->assertNotSame(
            $challenge->starter_code,
            $solution,
            $slug.': the solution is just the starter code',
        );

        if ($criteria['landing_required']) {
            $this->assertStringContainsString('drone.land()', $solution, $slug.': never lands');
        }

        // A photo mission's solution has to actually take the photos the
        // grader counts, otherwise it cannot score.
        $minPhotos = $criteria['min_photos'] ?? 0;

        if ($minPhotos > 0) {
            $this->assertGreaterThanOrEqual(
                $minPhotos,
                mb_substr_count($solution, 'drone.takePhoto'),
                $slug.': fewer takePhoto calls than the mission requires',
            );
        }
    });
});

test('city operations exercises the full mission toolkit', function (): void {
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
});

/**
 * The numbers the Starter tier is sold on, asserted against the data that
 * has to honour them.
 *
 * docs/pricing.md promises "3 beginner courses (5 missions)". If this test
 * fails, either the seeder or the pricing copy moved without the other, and
 * one of the two is now a lie to a paying customer.
 */
test('the starter split matches the pricing copy', function (): void {
    $this->seed(CourseSeeder::class);

    $starterCourses = Course::query()
        ->where('required_plan', Plan::Starter->value)
        ->orderBy('order')
        ->pluck('slug')
        ->all();

    $this->assertSame(
        ['drone-basics', 'precision-flight', 'sensor-flight'],
        $starterCourses,
    );

    $flyable = Challenge::query()
        ->with('course')
        ->get()
        ->filter(fn (Challenge $challenge): bool => Plan::Starter->covers(
            $challenge->requiredPlanIn($challenge->course),
        ));

    $this->assertCount(5, $flyable, 'Starter must be able to fly exactly five missions.');
    $this->assertSame(
        ['drone-basics'],
        $flyable->pluck('course.slug')->unique()->values()->all(),
        'The five free missions should be one complete course, not five scattered ones.',
    );
});

test('every seeded mission resolves to a real plan', function (): void {
    $this->seed(CourseSeeder::class);

    Challenge::query()->with('course')->get()->each(function (Challenge $challenge): void {
        $plan = $challenge->requiredPlanIn($challenge->course);

        $this->assertContains(
            $plan,
            [Plan::Starter, Plan::Pro],
            sprintf('mission %s resolves to an unexpected tier', $challenge->slug),
        );
    });
});

/**
 * The ground plane is centered on the origin, so anything playable must sit
 * inside half the width/depth and below the height ceiling — a goal or
 * waypoint outside the pad renders (and lands the drone) off the world.
 *
 * @param  array<string, mixed>  $point
 * @param  array<string, mixed>  $bounds
 */
function assertPointWithinBounds(array $point, array $bounds, string $context): void
{
    test()->assertLessThanOrEqual($bounds['width'] / 2, abs($point['x']), $context.': x outside ground plane');
    test()->assertLessThanOrEqual($bounds['depth'] / 2, abs($point['z']), $context.': z outside ground plane');
    test()->assertGreaterThan(0, $point['y'], $context.': y below ground');
    test()->assertLessThanOrEqual($bounds['height'], $point['y'], $context.': y above bounds');
}
