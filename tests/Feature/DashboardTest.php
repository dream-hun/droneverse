<?php

declare(strict_types=1);

use App\Enums\ChallengeStatus;
use App\Enums\Plan;
use App\Models\Challenge;
use App\Models\Course;
use App\Models\User;
use App\Models\UserChallengeProgress;

test('guests are redirected to the login page', function (): void {
    $response = $this->get(route('dashboard'));
    $response->assertRedirect(route('login'));
});

test('authenticated users can visit the dashboard', function (): void {
    $user = User::factory()->create();
    $this->actingAs($user);

    $response = $this->get(route('dashboard'));
    $response->assertOk();
});

test('dashboard reports course progress and stats', function (): void {
    $user = User::factory()->create();
    $course = Course::factory()->create(['title' => 'Drone Basics']);
    $completed = Challenge::factory()->for($course)->create();
    Challenge::factory()->for($course)->create();

    UserChallengeProgress::factory()->completed()->create([
        'user_id' => $user->id,
        'challenge_id' => $completed->id,
    ]);

    $response = $this->actingAs($user)->get(route('dashboard'));

    $response->assertInertia(fn ($page) => $page
        ->component('dashboard')
        ->where('stats.completed', 1)
        ->where('stats.stars', 3)
        ->missing('courses')
        ->loadDeferredProps(fn ($reload) => $reload
            ->has('courses', 1)
            ->where('courses.0.title', 'Drone Basics')
            ->where('courses.0.challengesCount', 2)
            ->where('courses.0.completedCount', 1)));
});

test('continue skips challenges that are no longer published', function (): void {
    $user = User::factory()->create();
    $course = Course::factory()->create();
    $publishedChallenge = Challenge::factory()->for($course)->create();
    $unpublishedChallenge = Challenge::factory()->for($course)->unpublished()->create();

    UserChallengeProgress::factory()->create([
        'user_id' => $user->id,
        'challenge_id' => $publishedChallenge->id,
        'status' => ChallengeStatus::InProgress,
        'updated_at' => now()->subDay(),
    ]);
    UserChallengeProgress::factory()->create([
        'user_id' => $user->id,
        'challenge_id' => $unpublishedChallenge->id,
        'status' => ChallengeStatus::InProgress,
        'updated_at' => now(),
    ]);

    $response = $this->actingAs($user)->get(route('dashboard'));

    $response->assertInertia(fn ($page) => $page
        ->where('continue.challengeSlug', $publishedChallenge->slug));
});

test('continue is empty when the started challenge is no longer covered by the plan', function (): void {
    $user = User::factory()->create();
    $course = Course::factory()->requiring(Plan::Pro)->create();
    $challenge = Challenge::factory()->for($course)->create();

    UserChallengeProgress::factory()->create([
        'user_id' => $user->id,
        'challenge_id' => $challenge->id,
        'status' => ChallengeStatus::InProgress,
    ]);

    $response = $this->actingAs($user)->get(route('dashboard'));

    $response->assertInertia(fn ($page) => $page->where('continue', null));
});

test('continue offers a locked mission to a pilot whose plan covers it', function (): void {
    $user = User::factory()->onPlan(Plan::Pro)->create();
    $course = Course::factory()->requiring(Plan::Pro)->create();
    $challenge = Challenge::factory()->for($course)->create();

    UserChallengeProgress::factory()->create([
        'user_id' => $user->id,
        'challenge_id' => $challenge->id,
        'status' => ChallengeStatus::InProgress,
    ]);

    $response = $this->actingAs($user)->get(route('dashboard'));

    $response->assertInertia(fn ($page) => $page
        ->where('continue.challengeSlug', $challenge->slug));
});

test('continue is empty when the started challenge is in an unpublished course', function (): void {
    $user = User::factory()->create();
    $course = Course::factory()->unpublished()->create();
    $challenge = Challenge::factory()->for($course)->create();

    UserChallengeProgress::factory()->create([
        'user_id' => $user->id,
        'challenge_id' => $challenge->id,
        'status' => ChallengeStatus::InProgress,
    ]);

    $response = $this->actingAs($user)->get(route('dashboard'));

    $response->assertInertia(fn ($page) => $page->where('continue', null));
});

test('progress counts exclude unpublished challenges', function (): void {
    $user = User::factory()->create();
    $course = Course::factory()->create();
    $publishedChallenge = Challenge::factory()->for($course)->create();
    $unpublishedChallenge = Challenge::factory()->for($course)->unpublished()->create();

    UserChallengeProgress::factory()->completed()->create([
        'user_id' => $user->id,
        'challenge_id' => $publishedChallenge->id,
    ]);
    UserChallengeProgress::factory()->completed()->create([
        'user_id' => $user->id,
        'challenge_id' => $unpublishedChallenge->id,
    ]);

    $response = $this->actingAs($user)->get(route('dashboard'));

    $response->assertInertia(fn ($page) => $page
        ->where('stats.completed', 1)
        ->where('stats.stars', 3)
        ->loadDeferredProps(fn ($reload) => $reload
            ->where('courses.0.challengesCount', 1)
            ->where('courses.0.completedCount', 1)));
});

test('progress counts exclude challenges in unpublished courses', function (): void {
    $user = User::factory()->create();
    $course = Course::factory()->unpublished()->create();
    $challenge = Challenge::factory()->for($course)->create();

    UserChallengeProgress::factory()->completed()->create([
        'user_id' => $user->id,
        'challenge_id' => $challenge->id,
    ]);

    $response = $this->actingAs($user)->get(route('dashboard'));

    $response->assertInertia(fn ($page) => $page
        ->where('stats.completed', 0)
        ->where('stats.stars', 0)
        ->loadDeferredProps(fn ($reload) => $reload
            ->has('courses', 0)));
});
