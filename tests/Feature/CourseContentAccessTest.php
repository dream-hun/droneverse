<?php

declare(strict_types=1);

use App\Enums\Plan;
use App\Models\Challenge;
use App\Models\Course;
use App\Models\Quiz;
use App\Models\User;

/*
 * The catalogue's access rules, asserted once against both kinds of content.
 *
 * {@see CourseContent} exists because a mission and a quiz answered these
 * questions in two separately-maintained copies that were only required to
 * agree by a comment. Every case here therefore runs against both models: a
 * rule that stops holding for one of them is the drift the trait was
 * extracted to make impossible, and it should fail here rather than as a
 * mission nobody could open or a quiz anyone could.
 *
 * The per-model tests still cover what only one of them does — the solution
 * unlock in ChallengeTest, the pass mark in QuizTest.
 */

/**
 * Both models, by name; {@see courseContent()} makes one.
 */
dataset('content', [
    'challenge' => ['challenge'],
    'quiz' => ['quiz'],
]);

/**
 * A challenge or a quiz in the given course, whichever the dataset named.
 *
 * @param  array<string, mixed>  $attributes
 */
function courseContent(string $kind, Course $course, array $attributes): Challenge|Quiz
{
    return match ($kind) {
        'challenge' => Challenge::factory()->for($course)->create($attributes),
        'quiz' => Quiz::factory()->for($course)->create($attributes),
        default => throw new InvalidArgumentException("Unknown course content [{$kind}]."),
    };
}

test('published content in a published course is available', function (string $kind): void {
    $course = Course::factory()->create(['is_published' => true]);

    expect(courseContent($kind, $course, ['is_published' => true])->isAvailableIn($course))->toBeTrue();
})->with('content');

test('unpublished content is not available', function (string $kind): void {
    $course = Course::factory()->create(['is_published' => true]);

    expect(courseContent($kind, $course, ['is_published' => false])->isAvailableIn($course))->toBeFalse();
})->with('content');

/**
 * Publishing content inside a course that is not live does not leak it.
 */
test('content in an unpublished course is not available', function (string $kind): void {
    $course = Course::factory()->create(['is_published' => false]);

    expect(courseContent($kind, $course, ['is_published' => true])->isAvailableIn($course))->toBeFalse();
})->with('content');

/**
 * The check a URL carrying two independent slugs exists for.
 */
test('content is not available in a course it does not belong to', function (string $kind): void {
    $owner = Course::factory()->create(['is_published' => true]);
    $other = Course::factory()->create(['is_published' => true]);

    expect(courseContent($kind, $owner, ['is_published' => true])->isAvailableIn($other))->toBeFalse();
})->with('content');

test('content without a plan of its own inherits its courses', function (string $kind): void {
    $course = Course::factory()->create(['required_plan' => Plan::Pro->value]);

    expect(courseContent($kind, $course, ['required_plan' => null])->requiredPlanIn($course))->toBe(Plan::Pro);
})->with('content');

/**
 * How a Starter course can hold Pro-only content.
 */
test('content with a plan of its own overrides its courses', function (string $kind): void {
    $course = Course::factory()->create(['required_plan' => Plan::Starter->value]);

    expect(courseContent($kind, $course, ['required_plan' => Plan::Pro->value])->requiredPlanIn($course))->toBe(Plan::Pro);
})->with('content');

test('a guest is treated as a starter pilot', function (string $kind): void {
    $course = Course::factory()->create(['required_plan' => Plan::Starter->value]);
    $starter = courseContent($kind, $course, ['required_plan' => Plan::Starter->value]);
    $pro = courseContent($kind, $course, ['required_plan' => Plan::Pro->value]);

    expect($starter->isUnlockedFor(null, $course))->toBeTrue();
    expect($pro->isUnlockedFor(null, $course))->toBeFalse();
})->with('content');

test('a plan unlocks the tiers it covers', function (string $kind): void {
    $course = Course::factory()->create(['required_plan' => Plan::Starter->value]);
    $pro = courseContent($kind, $course, ['required_plan' => Plan::Pro->value]);

    $starterPilot = User::factory()->create(['plan_override' => Plan::Starter->value]);
    $proPilot = User::factory()->create(['plan_override' => Plan::Pro->value]);

    expect($pro->isUnlockedFor($starterPilot, $course))->toBeFalse();
    expect($pro->isUnlockedFor($proPilot, $course))->toBeTrue();
})->with('content');

/**
 * A stored plan name that no longer names a tier degrades open.
 *
 * The catalogue should keep working when a tier is renamed or retired,
 * and the fallback runs through the course, so it is worth pinning that
 * both kinds of content fall back the same way.
 */
test('an unrecognised plan name falls back to starter', function (string $kind): void {
    $course = Course::factory()->create(['required_plan' => 'legacy-tier']);

    expect(courseContent($kind, $course, ['required_plan' => 'legacy-tier'])->requiredPlanIn($course))->toBe(Plan::Starter);
})->with('content');
