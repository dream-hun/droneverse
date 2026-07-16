<?php

namespace Tests\Feature;

use App\Enums\ChallengeStatus;
use App\Models\Challenge;
use App\Models\Course;
use App\Models\User;
use App\Models\UserChallengeProgress;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CourseTest extends TestCase
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
            ->has('courses', 1)
            ->where('courses.0.title', 'Drone Basics'));
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
            ->where('course.slug', $course->slug)
            ->has('challenges', 1)
            ->where('challenges.0.title', 'Hover & Land')
            ->where('challenges.0.status', ChallengeStatus::NotStarted));
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
            ->where('challenges.0.status', ChallengeStatus::Completed)
            ->where('challenges.0.stars', 3));
    }
}
