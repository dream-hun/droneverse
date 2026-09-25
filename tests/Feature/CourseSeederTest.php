<?php

declare(strict_types=1);

use App\Enums\Plan;
use App\Models\Challenge;
use App\Models\Course;
use Database\Seeders\CourseSeeder;
use Pest\Matchers\Any;

test('seeding twice creates no duplicates', function (): void {
    $this->seed(CourseSeeder::class);

    expect(Course::query()->count())->toBe(5);
    expect(Challenge::query()->count())->toBe(20);

    $this->seed(CourseSeeder::class);

    expect(Course::query()->count())->toBe(5);
    expect(Challenge::query()->count())->toBe(20);
});

test('seeded catalog is published and ordered', function (): void {
    $this->seed(CourseSeeder::class);

    expect(Course::query()->orderBy('order')->pluck('slug')->all())
        ->toBe(['drone-basics', 'precision-flight', 'sensor-flight', 'delivery-ops', 'city-operations']);

    expect(Course::query()->where('is_published', false)->doesntExist())->toBeTrue();
    expect(Challenge::query()->where('is_published', false)->doesntExist())->toBeTrue();

    Course::query()->withCount('challenges')->get()->each(function (Course $course): void {
        expect($course->challenges_count)->toBeGreaterThanOrEqual(1, sprintf('course %s has no challenges', $course->slug));
    });
});

test('seeded challenges have playable environments', function (): void {
    $this->seed(CourseSeeder::class);

    Challenge::query()->get()->each(function (Challenge $challenge): void {
        $slug = $challenge->slug;
        $environment = $challenge->environment;
        $criteria = $challenge->success_criteria;

        $this->assertStringContainsString('async function main(drone)', $challenge->starter_code, $slug);
        expect($challenge->max_score)->toBe(100, $slug);

        foreach (['x', 'y', 'z', 'yaw'] as $key) {
            expect($environment['start'])->toHaveKey($key, new Any, sprintf('%s: start.%s', $slug, $key));
        }

        $bounds = $environment['bounds'];

        foreach (['width', 'depth', 'height'] as $key) {
            expect($bounds)->toHaveKey($key, new Any, sprintf('%s: bounds.%s', $slug, $key));
            expect($bounds[$key])->toBeGreaterThan(0, sprintf('%s: bounds.%s', $slug, $key));
        }

        expect($criteria['type'])->toBeIn(['waypoints', 'gates'], $slug);
        expect($criteria['avoid_collisions'] ?? null)->toBeBool($slug);
        expect($criteria['landing_required'] ?? null)->toBeBool($slug);
        expect($criteria['waypoints'] ?? null)->toBeArray($slug);
        expect($criteria['max_time_seconds'])->toBeGreaterThanOrEqual(10, $slug.': max_time_seconds');

        if (array_key_exists('min_altitude', $criteria)) {
            expect($criteria['min_altitude'])->toBeGreaterThan(0, $slug.': min_altitude');
            expect($criteria['min_altitude'])->toBeLessThanOrEqual($bounds['height'], $slug.': min_altitude exceeds bounds');
        }

        expect($environment['goal']['radius'])->toBeGreaterThan(0, $slug.': goal radius');
        assertPointWithinBounds($environment['goal'] + ['y' => 1], $bounds, $slug.': goal');

        foreach ($criteria['waypoints'] ?? [] as $index => $waypoint) {
            expect($waypoint['radius'])
                ->toBeGreaterThanOrEqual(0.5, sprintf('%s: criteria waypoint %s radius too small to hit', $slug, $index));
            assertPointWithinBounds($waypoint, $bounds, sprintf('%s: criteria waypoint %s', $slug, $index));
        }

        foreach ($environment['waypoints'] as $index => $waypoint) {
            assertPointWithinBounds($waypoint, $bounds, sprintf('%s: environment waypoint %s', $slug, $index));
        }

        expect($environment)->toHaveKey('obstacles', new Any, $slug.': obstacles');

        foreach ($environment['obstacles'] ?? [] as $index => $obstacle) {
            assertPointWithinBounds($obstacle, $bounds, sprintf('%s: obstacle %s', $slug, $index));
        }

        foreach ($environment['props'] ?? [] as $index => $prop) {
            expect($prop['kind'])->toBeIn(['car', 'van', 'tree'], sprintf('%s: prop %s kind', $slug, $index));
            assertPointWithinBounds($prop + ['y' => 1], $bounds, sprintf('%s: prop %s', $slug, $index));
        }

        if (array_key_exists('carwash', $environment)) {
            assertPointWithinBounds($environment['carwash'] + ['y' => 1], $bounds, $slug.': carwash');
        }

        if (array_key_exists('photo_targets', $criteria)) {
            expect($criteria['photo_targets'])->not->toBeEmpty($slug.': photo_targets empty');

            foreach ($criteria['photo_targets'] as $index => $target) {
                expect($target['radius'])
                    ->toBeGreaterThanOrEqual(1, sprintf('%s: photo target %s radius too small to hit', $slug, $index));
                assertPointWithinBounds($target + ['y' => 1], $bounds, sprintf('%s: photo target %s', $slug, $index));
            }
        }

        if (array_key_exists('min_photos', $criteria)) {
            expect($criteria['min_photos'])->toBeGreaterThanOrEqual(1, $slug.': min_photos');
            expect($criteria['min_photos'])->toBeLessThanOrEqual(10, $slug.': min_photos unreasonably high');
        }

        if (! empty($criteria['wash_required'])) {
            expect($environment)->toHaveKey('carwash', new Any, $slug.': wash required but no carwash in the environment');
        }
    });
});

test('every seeded challenge ships a reference solution', function (): void {
    $this->seed(CourseSeeder::class);

    Challenge::query()->get()->each(function (Challenge $challenge): void {
        $slug = $challenge->slug;
        $solution = $challenge->solution_code ?? '';
        $criteria = $challenge->success_criteria;

        expect($solution)->not->toBeEmpty($slug.': no reference solution');
        $this->assertStringContainsString('async function main(drone)', $solution, $slug);
        expect($solution)->not->toBe($challenge->starter_code, $slug.': the solution is just the starter code');

        if ($criteria['landing_required'] ?? false) {
            $this->assertStringContainsString('drone.land()', $solution, $slug.': never lands');
        }

        // A photo mission's solution has to actually take the photos the
        // grader counts, otherwise it cannot score.
        $minPhotos = $criteria['min_photos'] ?? 0;

        if ($minPhotos > 0) {
            expect(mb_substr_count($solution, 'drone.takePhoto'))
                ->toBeGreaterThanOrEqual($minPhotos, $slug.': fewer takePhoto calls than the mission requires');
        }
    });
});

test('city operations exercises the full mission toolkit', function (): void {
    $this->seed(CourseSeeder::class);

    $course = Course::query()->where('slug', 'city-operations')->sole();
    $challenges = $course->challenges()->orderBy('order')->get()->keyBy('slug');

    expect($challenges->keys()->all())
        ->toBe(['downtown-gauntlet', 'street-sweep', 'wash-and-return', 'skyline-survey', 'full-shift']);

    $sweep = $challenges->sole('slug', 'street-sweep');
    expect($sweep->success_criteria['photo_targets'] ?? [])->not->toBeEmpty();
    expect($sweep->starter_code)->toContain('drone.scan');
    expect(collect($sweep->environment['props'] ?? [])->contains(fn (array $prop): bool => ($prop['label'] ?? null) === 'delivery-van'))
        ->toBeTrue('street-sweep needs the delivery-van prop its scanner mission hunts for');

    $wash = $challenges->sole('slug', 'wash-and-return');
    expect($wash->success_criteria['wash_required'] ?? null)->toBeTrue();
    expect($wash->environment)->toHaveKey('carwash');

    $survey = $challenges->sole('slug', 'skyline-survey');
    expect($survey->success_criteria['min_photos'] ?? null)->toBe(3);
    expect($survey->success_criteria['photo_targets'] ?? [])->toHaveCount(3);
    expect($survey->starter_code)->toContain('drone.takePhoto');

    $shift = $challenges->sole('slug', 'full-shift');
    expect($shift->success_criteria['wash_required'] ?? null)->toBeTrue();
    expect($shift->success_criteria['photo_targets'] ?? [])->not->toBeEmpty();
    expect($shift->success_criteria['waypoints'] ?? [])->not->toBeEmpty();
    expect($shift->environment)->toHaveKey('carwash');
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

    expect($starterCourses)->toBe(['drone-basics', 'precision-flight', 'sensor-flight']);

    $flyable = Challenge::query()
        ->with('course')
        ->get()
        ->filter(fn (Challenge $challenge): bool => Plan::Starter->covers(
            $challenge->requiredPlanIn($challenge->course),
        ));

    expect($flyable)->toHaveCount(5, 'Starter must be able to fly exactly five missions.');
    expect($flyable->pluck('course.slug')->unique()->values()->all())
        ->toBe(['drone-basics'], 'The five free missions should be one complete course, not five scattered ones.');
});

test('every seeded mission resolves to a real plan', function (): void {
    $this->seed(CourseSeeder::class);

    Challenge::query()->with('course')->get()->each(function (Challenge $challenge): void {
        $plan = $challenge->requiredPlanIn($challenge->course);

        expect($plan)->toBeIn([Plan::Starter, Plan::Pro], sprintf('mission %s resolves to an unexpected tier', $challenge->slug));
    });
});

/**
 * The ground plane is centered on the origin, so anything playable must sit
 * inside half the width/depth and below the height ceiling — a goal or
 * waypoint outside the pad renders (and lands the drone) off the world.
 *
 * @param  array{x: float|int, y: float|int, z: float|int, ...}  $point
 * @param  array{width: float|int, depth: float|int, height: float|int}  $bounds
 */
function assertPointWithinBounds(array $point, array $bounds, string $context): void
{
    expect(abs($point['x']))->toBeLessThanOrEqual($bounds['width'] / 2, $context.': x outside ground plane');
    expect(abs($point['z']))->toBeLessThanOrEqual($bounds['depth'] / 2, $context.': z outside ground plane');
    expect($point['y'])->toBeGreaterThan(0, $context.': y below ground');
    expect($point['y'])->toBeLessThanOrEqual($bounds['height'], $context.': y above bounds');
}
