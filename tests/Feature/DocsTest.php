<?php

declare(strict_types=1);

use App\Actions\BuildDroneManual;
use App\Actions\GradeSimulatorRun;
use App\Models\Course;
use App\Models\User;

test('guests can read the whole manual', function (): void {
    $response = $this->get(route('docs'));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('docs')
        ->has('manual.concepts')
        ->has('manual.commandGroups')
        ->has('manual.examples')
        ->has('manual.pitfalls')
        ->has('manual.scoring')
        // The page is one document assembled from config and a two-column
        // pluck, so none of it waits for a second round trip.
        ->where('manual.tagline', config('drone-api.manual.tagline')));
});

test('a signed-in pilot reads the same page', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('docs'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('docs'));
});

/*
 * The point of this page as against a course guide: a guide names the eight or
 * ten commands its course leans on, and this names all of them. A command that
 * shipped without reaching the manual is a command nobody can look up.
 */
test('the manual describes every command in the reference', function (): void {
    $manual = resolve(BuildDroneManual::class)->handle();

    $described = collect($manual['commandGroups'])
        ->flatMap(fn (array $group): array => array_column($group['commands'], 'name'))
        ->sort()
        ->values()
        ->all();

    $reference = collect(array_keys(config('drone-api.commands')))
        ->sort()
        ->values()
        ->all();

    expect($described)->toBe($reference);
});

test('the manual carries every worked example and every pitfall', function (): void {
    $manual = resolve(BuildDroneManual::class)->handle();

    $examples = collect($manual['examples'])->sum(fn (array $group): int => count($group['items']));
    $pitfalls = collect($manual['pitfalls'])->sum(fn (array $group): int => count($group['items']));

    expect($examples)->toBe(
        collect(config('course-docs'))->sum(fn (array $doc): int => count($doc['examples'] ?? []))
    );

    expect($pitfalls)->toBe(
        collect(config('course-docs'))->sum(fn (array $doc): int => count($doc['pitfalls'] ?? []))
    );
});

/*
 * Example slugs are rendered as element ids and linked to. config/course-docs
 * only requires them to be unique within a course, so the manual prefixes them
 * — without that, two courses picking the same slug would put two headings
 * behind one anchor.
 */
test('every example on the page has an id of its own', function (): void {
    $manual = resolve(BuildDroneManual::class)->handle();

    $slugs = collect($manual['examples'])
        ->flatMap(fn (array $group): array => array_column($group['items'], 'slug'))
        ->all();

    expect($slugs)->toBe(array_values(array_unique($slugs)));
});

/*
 * The scoring section is built from the grader's own constants rather than
 * written out in prose. This is what would fail if somebody reintroduced a
 * second copy of the policy, or changed the policy without the page following.
 */
test('the published scoring policy is the grader own', function (): void {
    $scoring = resolve(BuildDroneManual::class)->handle()['scoring'];

    expect(array_column($scoring['weights'], 'points'))->toBe([
        GradeSimulatorRun::OBJECTIVE_WEIGHT,
        GradeSimulatorRun::LANDING_WEIGHT,
        GradeSimulatorRun::TIME_WEIGHT,
    ]);

    expect($scoring['total'])->toBe(100)
        ->and(collect($scoring['weights'])->sum('points'))->toBe($scoring['total'])
        ->and($scoring['collisionPenalty'])->toBe(GradeSimulatorRun::COLLISION_PENALTY)
        ->and($scoring['stars'])->toHaveCount(GradeSimulatorRun::MAX_STARS);

    // The speed star's threshold is a percentage of the grader's ratio, not a
    // number somebody typed next to it.
    expect($scoring['stars'][2])->toContain(
        sprintf('%d%%', (int) round(GradeSimulatorRun::FAST_FINISH_RATIO * 100))
    );
});

/*
 * The examples are attributed to the course they were written for, and the
 * attribution is a link only when there is somewhere live to send a reader.
 */
test('examples link to a published course and only name an unavailable one', function (): void {
    Course::factory()->create(['slug' => 'drone-basics', 'title' => 'Drone Basics']);
    Course::factory()->unpublished()->create(['slug' => 'sensor-flight']);

    $groups = collect(resolve(BuildDroneManual::class)->handle()['examples'])
        ->keyBy(fn (array $group): string => $group['course']['slug']);

    expect($groups['drone-basics']['course']['published'])->toBeTrue()
        ->and($groups['drone-basics']['course']['title'])->toBe('Drone Basics');

    expect($groups['sensor-flight']['course']['published'])->toBeFalse();

    // No row at all, so the slug is read back as words rather than printed raw.
    expect($groups['precision-flight']['course']['published'])->toBeFalse()
        ->and($groups['precision-flight']['course']['title'])->toBe('Precision Flight');
});
