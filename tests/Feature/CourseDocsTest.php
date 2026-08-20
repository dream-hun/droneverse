<?php

declare(strict_types=1);

use App\Enums\Plan;
use App\Models\Challenge;
use App\Models\Course;
use App\Models\User;
use Database\Seeders\CourseSeeder;

test('guests can read a course guide', function (): void {
    $course = Course::factory()->create(['slug' => 'drone-basics', 'title' => 'Drone Basics']);
    Challenge::factory()->for($course)->create(['title' => 'Hover & Land']);

    $response = $this->get(route('courses.docs', $course));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('courses/docs')
        ->where('course.slug', 'drone-basics')
        // The guide is config, so it costs no query and does not defer.
        ->has('documentation.objectives')
        ->has('documentation.commandGroups')
        ->has('documentation.examples')
        // Only the mission index waits.
        ->missing('missions')
        ->loadDeferredProps(fn ($reload) => $reload
            ->has('missions', 1)
            ->where('missions.0.title', 'Hover & Land')
            ->where('missions.0.locked', false)));
});

test('a course with no written guide has no docs page', function (): void {
    $course = Course::factory()->create(['slug' => 'undocumented-course']);

    $this->get(route('courses.docs', $course))->assertNotFound();
});

test('an unpublished course has no docs page', function (): void {
    $course = Course::factory()->unpublished()->create(['slug' => 'drone-basics']);

    $this->get(route('courses.docs', $course))->assertNotFound();
});

test('the course page links to the guide only when one exists', function (): void {
    $documented = Course::factory()->create(['slug' => 'drone-basics']);
    $undocumented = Course::factory()->create(['slug' => 'undocumented-course']);

    $this->get(route('courses.show', $documented))
        ->assertInertia(fn ($page) => $page->where('hasDocs', true));

    $this->get(route('courses.show', $undocumented))
        ->assertInertia(fn ($page) => $page->where('hasDocs', false));
});

test('a locked mission is listed in the guide but marked locked', function (): void {
    $course = Course::factory()->create(['slug' => 'sensor-flight']);
    Challenge::factory()->for($course)->requiring(Plan::Pro)->create(['title' => 'Wall Finder']);

    $this->get(route('courses.docs', $course))
        ->assertInertia(fn ($page) => $page
            ->loadDeferredProps(fn ($reload) => $reload
                ->where('missions.0.title', 'Wall Finder')
                ->where('missions.0.locked', true)));
});

test('a paid pilot sees the same mission unlocked', function (): void {
    $user = User::factory()->onPlan(Plan::Pro)->create();
    $course = Course::factory()->create(['slug' => 'sensor-flight']);
    Challenge::factory()->for($course)->requiring(Plan::Pro)->create();

    $this->actingAs($user)
        ->get(route('courses.docs', $course))
        ->assertInertia(fn ($page) => $page
            ->loadDeferredProps(fn ($reload) => $reload
                ->where('missions.0.locked', false)));
});

test('a guide is published for every seeded course', function (): void {
    $this->seed(CourseSeeder::class);

    Course::query()->get()->each(function (Course $course): void {
        $this->get(route('courses.docs', $course))
            ->assertOk(sprintf('course %s has no documentation page', $course->slug));
    });
});

/*
 * The join in App\Actions\BuildCourseDocumentation drops a command name it
 * cannot find rather than rendering a blank row, so a typo in a course's
 * `commands` list is invisible on the page. This is what catches it.
 */
test('every command a guide names exists in the API reference', function (): void {
    $reference = config('drone-api.commands');
    $groups = array_keys(config('drone-api.groups'));

    foreach (config('course-docs') as $slug => $doc) {
        foreach ($doc['commands'] as $command) {
            $this->assertArrayHasKey(
                $command,
                $reference,
                sprintf('%s names an unknown command: %s', $slug, $command),
            );

            $this->assertContains(
                $reference[$command]['group'],
                $groups,
                sprintf('%s is in a group the reference does not render', $command),
            );
        }
    }
});

test('every worked example is a complete, uniquely addressable program', function (): void {
    foreach (config('course-docs') as $slug => $doc) {
        expect($doc['examples'])->not->toBeEmpty(sprintf('%s has no worked examples', $slug));

        $slugs = array_column($doc['examples'], 'slug');

        // The slugs are rendered as element ids and linked to, so a repeat
        // would make one of the two anchors unreachable.
        expect($slugs)->toBe(array_unique($slugs), sprintf('%s repeats an example slug', $slug));

        foreach ($doc['examples'] as $example) {
            $this->assertStringContainsString(
                'async function main(drone)',
                $example['code'],
                sprintf('%s: %s is not a runnable program', $slug, $example['slug']),
            );
        }
    }
});

/*
 * The examples are documentation, not answers. App\Models\Challenge gates
 * `solution_code` behind a completion or three runs, and this page is open to
 * anybody — so a guide that shipped a solution verbatim would hand it over to
 * anyone who guessed the URL.
 */
test('no worked example is a mission solution', function (): void {
    $this->seed(CourseSeeder::class);

    $solutions = Challenge::query()
        ->whereNotNull('solution_code')
        ->pluck('solution_code', 'slug');

    foreach (config('course-docs') as $courseSlug => $doc) {
        foreach ($doc['examples'] as $example) {
            foreach ($solutions as $missionSlug => $solution) {
                expect(normalize($example['code']))->not->toBe(
                    normalize($solution),
                    sprintf('%s/%s is the reference solution for %s', $courseSlug, $example['slug'], $missionSlug),
                );
            }
        }
    }
});

/** Whitespace-insensitive, so reindenting a solution would not slip past. */
function normalize(string $code): string
{
    return preg_replace('/\s+/', ' ', mb_trim($code)) ?? '';
}
