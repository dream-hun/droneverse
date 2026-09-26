<?php

declare(strict_types=1);

use App\Enums\AdminPermission;
use App\Models\Challenge;
use App\Models\Course;
use App\Models\DronePhoto;
use App\Models\User;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia;

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function coursePayload(array $overrides = []): array
{
    return [
        'title' => 'Night Ops',
        'slug' => 'night-ops',
        'description' => 'Flying after dark.',
        'difficulty' => 'advanced',
        'required_plan' => 'pro',
        'order' => 7,
        'is_published' => '1',
        ...$overrides,
    ];
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function missionPayload(array $overrides = []): array
{
    return [
        'title' => 'Dark Hover',
        'slug' => 'dark-hover',
        'briefing' => 'Hover in the dark.',
        'order' => 0,
        'difficulty' => 'advanced',
        'required_plan' => null,
        'starter_code' => "async function main(drone) {\n  await drone.takeoff();\n}\n",
        'solution_code' => null,
        'environment' => json_encode([
            'start' => ['x' => 0, 'y' => 0, 'z' => 0, 'yaw' => 0],
            'bounds' => ['width' => 20, 'depth' => 20, 'height' => 10],
            'gates' => [],
            'waypoints' => [],
            'goal' => ['x' => 0, 'z' => 0, 'radius' => 1.2],
        ]),
        'success_criteria' => json_encode([
            'type' => 'waypoints',
            'waypoints' => [],
            'avoid_collisions' => false,
            'max_time_seconds' => 30,
            'landing_required' => true,
        ]),
        'max_score' => 100,
        'is_published' => '1',
        ...$overrides,
    ];
}

test('staff without manage_courses cannot reach the catalogue', function (): void {
    $finance = User::factory()->withPermissions([AdminPermission::AccessAdmin, AdminPermission::ViewFinance])->create();

    $this->actingAs($finance)->get(route('admin.courses.index'))->assertForbidden();
});

test('lists drafts alongside published courses', function (): void {
    $editor = User::factory()->withPermissions([AdminPermission::AccessAdmin, AdminPermission::ManageCourses])->create();
    Course::factory()->unpublished()->create(['order' => 1]);
    Course::factory()->create(['order' => 0]);

    $this->actingAs($editor)
        ->get(route('admin.courses.index'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->component('admin/courses/index')
            ->has('courses', 2)
            ->where('courses.1.isPublished', false));
});

test('creates a course and lands on its page', function (): void {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)
        ->post(route('admin.courses.store'), coursePayload())
        ->assertRedirect(route('admin.courses.show', 'night-ops'));

    $course = Course::query()->where('slug', 'night-ops')->sole();

    expect($course->is_published)->toBeTrue()
        ->and($course->required_plan)->toBe('pro');
});

test('rejects a slug that is not URL-shaped', function (): void {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)
        ->post(route('admin.courses.store'), coursePayload(['slug' => 'Night Ops!']))
        ->assertSessionHasErrors(['slug' => 'Use lower-case letters, numbers and single hyphens, like drone-basics.']);
});

test('a renamed course sends its own page to the new slug', function (): void {
    $admin = User::factory()->admin()->create();
    $course = Course::factory()->create(['slug' => 'old-slug']);

    $this->actingAs($admin)
        ->from(route('admin.courses.show', $course))
        ->put(route('admin.courses.update', $course), coursePayload(['slug' => 'new-slug']))
        ->assertRedirect(route('admin.courses.show', 'new-slug'));
});

test('deleting a course removes its photo files', function (): void {
    Storage::fake('photos');

    $admin = User::factory()->admin()->create();
    $challenge = Challenge::factory()->create();
    $photo = DronePhoto::factory()->for($challenge)->create();
    Storage::disk('photos')->put($photo->path, 'image');

    $this->actingAs($admin)
        ->delete(route('admin.courses.destroy', $challenge->course))
        ->assertRedirect(route('admin.courses.index'));

    $this->assertModelMissing($challenge);
    Storage::disk('photos')->assertMissing($photo->path);
});

test("the course page includes each mission's reference solution", function (): void {
    $admin = User::factory()->admin()->create();
    $challenge = Challenge::factory()->create();

    $this->actingAs($admin)
        ->get(route('admin.courses.show', $challenge->course))
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->component('admin/courses/show')
            ->where('challenges.0.solutionCode', $challenge->solution_code));
});

test('creates a mission with its world decoded', function (): void {
    $admin = User::factory()->admin()->create();
    $course = Course::factory()->create();

    $this->actingAs($admin)
        ->post(route('admin.courses.challenges.store', $course), missionPayload())
        ->assertRedirect(route('admin.courses.show', $course));

    $challenge = $course->challenges()->where('slug', 'dark-hover')->sole();

    expect($challenge->environment['bounds']['width'])->toBe(20)
        ->and($challenge->required_plan)->toBeNull();
});

test('reports a world missing what the simulator needs under its field', function (): void {
    $admin = User::factory()->admin()->create();
    $course = Course::factory()->create();

    $this->actingAs($admin)
        ->post(route('admin.courses.challenges.store', $course), missionPayload([
            'environment' => json_encode(['bounds' => ['width' => 20, 'depth' => 20, 'height' => 10]]),
        ]))
        ->assertSessionHasErrors(['environment' => 'The start field is required.']);
});

test('rejects text that is not JSON as the grading rules', function (): void {
    $admin = User::factory()->admin()->create();
    $course = Course::factory()->create();

    $this->actingAs($admin)
        ->post(route('admin.courses.challenges.store', $course), missionPayload(['success_criteria' => '{not json']))
        ->assertSessionHasErrors('success_criteria');
});

test('a mission slug need only be unique within its course', function (): void {
    $admin = User::factory()->admin()->create();
    Challenge::factory()->create(['slug' => 'dark-hover']);
    $course = Course::factory()->create();

    $this->actingAs($admin)
        ->post(route('admin.courses.challenges.store', $course), missionPayload())
        ->assertSessionHasNoErrors();
});

test('a mission named under the wrong course is a 404', function (): void {
    $admin = User::factory()->admin()->create();
    $challenge = Challenge::factory()->create();
    $otherCourse = Course::factory()->create();

    $this->actingAs($admin)
        ->delete(route('admin.courses.challenges.destroy', [$otherCourse, $challenge]))
        ->assertNotFound();

    $this->assertModelExists($challenge);
});

test('an unticked publish box unpublishes the mission', function (): void {
    $admin = User::factory()->admin()->create();
    $challenge = Challenge::factory()->create();

    // An unticked checkbox sends nothing at all, which is what this mirrors.
    $payload = missionPayload(['slug' => $challenge->slug]);
    unset($payload['is_published']);

    $this->actingAs($admin)
        ->put(route('admin.courses.challenges.update', [$challenge->course, $challenge]), $payload)
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('admin.courses.show', $challenge->course));

    expect($challenge->fresh()?->is_published)->toBeFalse();
});
