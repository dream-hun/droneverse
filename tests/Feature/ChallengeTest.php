<?php

namespace Tests\Feature;

use App\Enums\ChallengeStatus;
use App\Models\Challenge;
use App\Models\Course;
use App\Models\User;
use App\Models\UserChallengeProgress;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ChallengeTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_to_the_login_page(): void
    {
        $course = Course::factory()->create();
        $challenge = Challenge::factory()->for($course)->create();

        $response = $this->get(route('challenges.show', [$course, $challenge]));

        $response->assertRedirect(route('login'));
    }

    public function test_authenticated_users_can_view_the_simulator(): void
    {
        $user = User::factory()->create();
        $course = Course::factory()->create();
        $challenge = Challenge::factory()->for($course)->create();

        $response = $this->actingAs($user)->get(route('challenges.show', [$course, $challenge]));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('challenges/play')
            ->where('challenge.slug', $challenge->slug)
            ->where('progress.status', ChallengeStatus::NotStarted));
    }

    public function test_challenge_from_a_different_course_returns_not_found(): void
    {
        $user = User::factory()->create();
        $course = Course::factory()->create();
        $otherCourse = Course::factory()->create();
        $challenge = Challenge::factory()->for($otherCourse)->create();

        $response = $this->actingAs($user)->get(route('challenges.show', [$course, $challenge]));

        $response->assertNotFound();
    }

    public function test_submitting_an_attempt_creates_progress(): void
    {
        $user = User::factory()->create();
        $course = Course::factory()->create();
        $challenge = Challenge::factory()->for($course)->create(['max_score' => 100]);

        $response = $this->actingAs($user)->post(route('challenges.attempts.store', [$course, $challenge]), [
            'score' => 80,
            'stars' => 2,
            'completed' => true,
            'code' => 'async function main(drone) { await drone.land(); }',
        ]);

        $response->assertOk();
        $response->assertJson([
            'status' => ChallengeStatus::Completed->value,
            'bestScore' => 80,
            'stars' => 2,
            'attempts' => 1,
        ]);

        $this->assertDatabaseHas('user_challenge_progress', [
            'user_id' => $user->id,
            'challenge_id' => $challenge->id,
            'status' => ChallengeStatus::Completed->value,
            'best_score' => 80,
            'stars' => 2,
            'attempts' => 1,
        ]);
    }

    public function test_attempts_clamp_out_of_range_scores_and_stars(): void
    {
        $user = User::factory()->create();
        $course = Course::factory()->create();
        $challenge = Challenge::factory()->for($course)->create(['max_score' => 100]);

        $response = $this->actingAs($user)->post(route('challenges.attempts.store', [$course, $challenge]), [
            'score' => 9999,
            'stars' => 99,
            'completed' => false,
            'code' => 'async function main(drone) {}',
        ]);

        $response->assertOk();
        $response->assertJson([
            'bestScore' => 100,
            'stars' => 3,
        ]);
    }

    public function test_an_incomplete_first_attempt_marks_the_challenge_in_progress(): void
    {
        $user = User::factory()->create();
        $course = Course::factory()->create();
        $challenge = Challenge::factory()->for($course)->create(['max_score' => 100]);

        $response = $this->actingAs($user)->post(route('challenges.attempts.store', [$course, $challenge]), [
            'score' => 25,
            'stars' => 0,
            'completed' => false,
            'code' => 'async function main(drone) { await drone.takeoff(); }',
        ]);

        $response->assertOk();
        $response->assertJson([
            'status' => ChallengeStatus::InProgress->value,
            'attempts' => 1,
        ]);

        $this->assertDatabaseHas('user_challenge_progress', [
            'user_id' => $user->id,
            'challenge_id' => $challenge->id,
            'status' => ChallengeStatus::InProgress->value,
        ]);
    }

    public function test_a_completed_challenge_is_not_downgraded_by_a_later_incomplete_attempt(): void
    {
        $user = User::factory()->create();
        $course = Course::factory()->create();
        $challenge = Challenge::factory()->for($course)->create(['max_score' => 100]);

        UserChallengeProgress::factory()->completed()->create([
            'user_id' => $user->id,
            'challenge_id' => $challenge->id,
        ]);

        $response = $this->actingAs($user)->post(route('challenges.attempts.store', [$course, $challenge]), [
            'score' => 10,
            'stars' => 0,
            'completed' => false,
            'code' => 'async function main(drone) {}',
        ]);

        $response->assertOk();
        $response->assertJson([
            'status' => ChallengeStatus::Completed->value,
            'attempts' => 2,
        ]);
    }

    public function test_best_score_never_decreases_across_attempts(): void
    {
        $user = User::factory()->create();
        $course = Course::factory()->create();
        $challenge = Challenge::factory()->for($course)->create(['max_score' => 100]);

        UserChallengeProgress::factory()->create([
            'user_id' => $user->id,
            'challenge_id' => $challenge->id,
            'best_score' => 90,
            'stars' => 3,
            'attempts' => 1,
        ]);

        $response = $this->actingAs($user)->post(route('challenges.attempts.store', [$course, $challenge]), [
            'score' => 40,
            'stars' => 1,
            'completed' => false,
            'code' => 'async function main(drone) {}',
        ]);

        $response->assertOk();
        $response->assertJson([
            'bestScore' => 90,
            'stars' => 3,
            'attempts' => 2,
        ]);
    }
}
