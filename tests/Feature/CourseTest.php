<?php

declare(strict_types=1);

use App\Enums\ChallengeStatus;
use App\Enums\Plan;
use App\Models\Challenge;
use App\Models\Course;
use App\Models\User;
use App\Models\UserChallengeProgress;

test('guests can browse published courses', function (): void {
    Course::factory()->create(['title' => 'Drone Basics']);
    Course::factory()->unpublished()->create(['title' => 'Hidden Course']);

    $response = $this->get(route('courses.index'));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('courses/index')
        // The catalog is deferred, so the first response carries the shell
        // and nothing else; the grid arrives on the follow-up request.
        ->missing('courses')
        ->loadDeferredProps(fn ($reload) => $reload
            ->has('courses', 1)
            ->where('courses.0.title', 'Drone Basics')));
});

test('guests can view a published course with its challenges', function (): void {
    $course = Course::factory()->create();
    Challenge::factory()->for($course)->create(['title' => 'Hover & Land']);
    Challenge::factory()->for($course)->unpublished()->create(['title' => 'Hidden Challenge']);

    $response = $this->get(route('courses.show', $course));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('courses/show')
        // The course header is eager; only the mission list waits.
        ->where('course.slug', $course->slug)
        ->missing('challenges')
        ->loadDeferredProps(fn ($reload) => $reload
            ->has('challenges', 1)
            ->where('challenges.0.title', 'Hover & Land')
            ->where('challenges.0.status', ChallengeStatus::NotStarted)));
});

test('unpublished course returns not found', function (): void {
    $course = Course::factory()->unpublished()->create();

    $response = $this->get(route('courses.show', $course));

    $response->assertNotFound();
});

test('course show reflects authenticated users progress', function (): void {
    $user = User::factory()->create();
    $course = Course::factory()->create();
    $challenge = Challenge::factory()->for($course)->create();

    UserChallengeProgress::factory()->completed()->create([
        'user_id' => $user->id,
        'challenge_id' => $challenge->id,
    ]);

    $response = $this->actingAs($user)->get(route('courses.show', $course));

    $response->assertInertia(fn ($page) => $page
        ->loadDeferredProps(fn ($reload) => $reload
            ->where('challenges.0.status', ChallengeStatus::Completed)
            ->where('challenges.0.stars', 3)));
});

test('a locked course still appears in the catalog', function (): void {
    Course::factory()->create(['title' => 'Free Course', 'order' => 0]);
    Course::factory()->requiring(Plan::Pro)->create(['title' => 'Paid Course', 'order' => 1]);

    $response = $this->get(route('courses.index'));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->loadDeferredProps(fn ($reload) => $reload
            ->has('courses', 2)
            ->where('courses.0.title', 'Free Course')
            ->where('courses.0.locked', false)
            ->where('courses.0.requiredPlan', 'starter')
            ->where('courses.1.title', 'Paid Course')
            ->where('courses.1.locked', true)
            ->where('courses.1.requiredPlan', 'pro')));
});

test('a paid viewer sees nothing locked', function (): void {
    $user = User::factory()->onPlan(Plan::Pro)->create();
    Course::factory()->requiring(Plan::Pro)->create();

    $this->actingAs($user)
        ->get(route('courses.index'))
        ->assertInertia(fn ($page) => $page
            ->loadDeferredProps(fn ($reload) => $reload
                ->where('courses.0.locked', false)));
});

test('a pro courses page is open to a starter pilot', function (): void {
    $course = Course::factory()->requiring(Plan::Pro)->create();
    Challenge::factory()->for($course)->create(['title' => 'Locked Mission']);

    $response = $this->get(route('courses.show', $course));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->where('course.requiredPlan', 'pro')
        ->loadDeferredProps(fn ($reload) => $reload
            ->has('challenges', 1)
            ->where('challenges.0.title', 'Locked Mission')
            ->where('challenges.0.locked', true)
            ->where('challenges.0.requiredPlan', 'pro')));
});

test('the briefing is still sent for a locked mission', function (): void {
    $course = Course::factory()->requiring(Plan::Pro)->create();
    $challenge = Challenge::factory()->for($course)->create();

    $this->get(route('courses.show', $course))
        ->assertInertia(fn ($page) => $page
            ->loadDeferredProps(fn ($reload) => $reload
                ->where('challenges.0.locked', true)
                ->where('challenges.0.briefing', $challenge->briefing)));
});

test('a course page mixes locked and unlocked missions', function (): void {
    $course = Course::factory()->create();
    Challenge::factory()->for($course)->create(['title' => 'Free', 'order' => 0]);
    Challenge::factory()->for($course)->requiring(Plan::Pro)->create(['title' => 'Paid', 'order' => 1]);

    $this->get(route('courses.show', $course))
        ->assertInertia(fn ($page) => $page
            ->loadDeferredProps(fn ($reload) => $reload
                ->where('challenges.0.title', 'Free')
                ->where('challenges.0.locked', false)
                ->where('challenges.1.title', 'Paid')
                ->where('challenges.1.locked', true)));
});
