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

final class CourseTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_can_browse_published_courses(): void
    {
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
    }

    public function test_guests_can_view_a_published_course_with_its_challenges(): void
    {
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
    }

    public function test_unpublished_course_returns_not_found(): void
    {
        $course = Course::factory()->unpublished()->create();

        $response = $this->get(route('courses.show', $course));

        $response->assertNotFound();
    }

    public function test_course_show_reflects_authenticated_users_progress(): void
    {
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
    }

    public function test_a_locked_course_still_appears_in_the_catalog(): void
    {
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
    }

    public function test_a_paid_viewer_sees_nothing_locked(): void
    {
        $user = User::factory()->onPlan(Plan::Pro)->create();
        Course::factory()->requiring(Plan::Pro)->create();

        $this->actingAs($user)
            ->get(route('courses.index'))
            ->assertInertia(fn ($page) => $page
                ->loadDeferredProps(fn ($reload) => $reload
                    ->where('courses.0.locked', false)));
    }

    public function test_a_pro_courses_page_is_open_to_a_starter_pilot(): void
    {
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
    }

    public function test_the_briefing_is_still_sent_for_a_locked_mission(): void
    {
        $course = Course::factory()->requiring(Plan::Pro)->create();
        $challenge = Challenge::factory()->for($course)->create();

        $this->get(route('courses.show', $course))
            ->assertInertia(fn ($page) => $page
                ->loadDeferredProps(fn ($reload) => $reload
                    ->where('challenges.0.locked', true)
                    ->where('challenges.0.briefing', $challenge->briefing)));
    }

    public function test_a_course_page_mixes_locked_and_unlocked_missions(): void
    {
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
    }
}
