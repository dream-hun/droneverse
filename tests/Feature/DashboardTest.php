<?php

namespace Tests\Feature;

use App\Models\Challenge;
use App\Models\Course;
use App\Models\User;
use App\Models\UserChallengeProgress;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_to_the_login_page()
    {
        $response = $this->get(route('dashboard'));
        $response->assertRedirect(route('login'));
    }

    public function test_authenticated_users_can_visit_the_dashboard()
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $response = $this->get(route('dashboard'));
        $response->assertOk();
    }

    public function test_dashboard_reports_course_progress_and_stats()
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
}
