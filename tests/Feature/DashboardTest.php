<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\ChallengeStatus;
use App\Enums\Plan;
use App\Models\Challenge;
use App\Models\Course;
use App\Models\User;
use App\Models\UserChallengeProgress;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class DashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_to_the_login_page(): void
    {
        $response = $this->get(route('dashboard'));
        $response->assertRedirect(route('login'));
    }

    public function test_authenticated_users_can_visit_the_dashboard(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $response = $this->get(route('dashboard'));
        $response->assertOk();
    }

    public function test_dashboard_reports_course_progress_and_stats(): void
    {
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
            ->has('courses', 1)
            ->where('courses.0.title', 'Drone Basics')
            ->where('courses.0.challengesCount', 2)
            ->where('courses.0.completedCount', 1)
            ->where('stats.completed', 1)
            ->where('stats.stars', 3));
    }

    public function test_continue_skips_challenges_that_are_no_longer_published(): void
    {
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
    }

    public function test_continue_is_empty_when_the_started_challenge_is_no_longer_covered_by_the_plan(): void
    {
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
    }

    public function test_continue_offers_a_locked_mission_to_a_pilot_whose_plan_covers_it(): void
    {
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
    }

    public function test_continue_is_empty_when_the_started_challenge_is_in_an_unpublished_course(): void
    {
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
    }

    public function test_progress_counts_exclude_unpublished_challenges(): void
    {
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
            ->where('courses.0.challengesCount', 1)
            ->where('courses.0.completedCount', 1)
            ->where('stats.completed', 1)
            ->where('stats.stars', 3));
    }

    public function test_progress_counts_exclude_challenges_in_unpublished_courses(): void
    {
        $user = User::factory()->create();
        $course = Course::factory()->unpublished()->create();
        $challenge = Challenge::factory()->for($course)->create();

        UserChallengeProgress::factory()->completed()->create([
            'user_id' => $user->id,
            'challenge_id' => $challenge->id,
        ]);

        $response = $this->actingAs($user)->get(route('dashboard'));

        $response->assertInertia(fn ($page) => $page
            ->has('courses', 0)
            ->where('stats.completed', 0)
            ->where('stats.stars', 0));
    }
}
