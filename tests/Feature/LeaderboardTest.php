<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Controllers\LeaderboardController;
use App\Models\Challenge;
use App\Models\Course;
use App\Models\User;
use App\Models\UserChallengeProgress;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class LeaderboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_to_the_login_page(): void
    {
        $response = $this->get(route('leaderboard'));

        $response->assertRedirect(route('login'));
    }

    public function test_pilots_are_ranked_by_points(): void
    {
        $course = Course::factory()->create();
        $challenge = Challenge::factory()->for($course)->create();

        $leader = User::factory()->create(['name' => 'Ada']);
        $runnerUp = User::factory()->create(['name' => 'Grace']);

        $this->recordProgress($leader, $challenge, points: 90, stars: 3);
        $this->recordProgress($runnerUp, $challenge, points: 40, stars: 1);

        $response = $this->actingAs($runnerUp)->get(route('leaderboard'));

        $response->assertInertia(fn ($page) => $page
            ->component('leaderboard/index')
            ->has('standings', 2)
            ->where('standings.0.name', 'Ada')
            ->where('standings.0.rank', 1)
            ->where('standings.0.points', 90)
            ->where('standings.0.stars', 3)
            ->where('standings.0.completed', 1)
            ->where('standings.0.isYou', false)
            ->where('standings.1.name', 'Grace')
            ->where('standings.1.rank', 2)
            ->where('standings.1.isYou', true)
            ->where('pilotCount', 2));
    }

    public function test_points_are_summed_across_every_challenge(): void
    {
        $course = Course::factory()->create();
        $first = Challenge::factory()->for($course)->create();
        $second = Challenge::factory()->for($course)->create();

        $allRounder = User::factory()->create(['name' => 'Ada']);
        $specialist = User::factory()->create(['name' => 'Grace']);

        $this->recordProgress($allRounder, $first, points: 60, stars: 2);
        $this->recordProgress($allRounder, $second, points: 60, stars: 2);
        $this->recordProgress($specialist, $first, points: 100, stars: 3);

        $response = $this->actingAs($allRounder)->get(route('leaderboard'));

        $response->assertInertia(fn ($page) => $page
            ->where('standings.0.name', 'Ada')
            ->where('standings.0.points', 120)
            ->where('standings.0.stars', 4)
            ->where('standings.0.completed', 2)
            ->where('standings.1.name', 'Grace')
            ->where('standings.1.points', 100));
    }

    public function test_stars_then_completions_break_a_points_tie(): void
    {
        $course = Course::factory()->create();
        $first = Challenge::factory()->for($course)->create();
        $second = Challenge::factory()->for($course)->create();

        $starred = User::factory()->create(['name' => 'Ada']);
        $unstarred = User::factory()->create(['name' => 'Grace']);

        $this->recordProgress($starred, $first, points: 100, stars: 3);
        $this->recordProgress($unstarred, $first, points: 50, stars: 1);
        $this->recordProgress($unstarred, $second, points: 50, stars: 1);

        $response = $this->actingAs($starred)->get(route('leaderboard'));

        $response->assertInertia(fn ($page) => $page
            ->where('standings.0.name', 'Ada')
            ->where('standings.0.rank', 1)
            ->where('standings.1.name', 'Grace')
            ->where('standings.1.rank', 2));
    }

    public function test_pilots_level_on_every_metric_share_a_rank(): void
    {
        $course = Course::factory()->create();
        $challenge = Challenge::factory()->for($course)->create();

        $first = User::factory()->create(['name' => 'Ada']);
        $second = User::factory()->create(['name' => 'Grace']);
        $third = User::factory()->create(['name' => 'Katherine']);

        $this->recordProgress($first, $challenge, points: 80, stars: 2);
        $this->recordProgress($second, $challenge, points: 80, stars: 2);
        $this->recordProgress($third, $challenge, points: 10, stars: 0);

        $response = $this->actingAs($first)->get(route('leaderboard'));

        $response->assertInertia(fn ($page) => $page
            ->where('standings.0.rank', 1)
            ->where('standings.1.rank', 1)
            ->where('standings.2.name', 'Katherine')
            ->where('standings.2.rank', 3));
    }

    public function test_the_board_can_be_scoped_to_a_single_course(): void
    {
        $flying = Course::factory()->create(['slug' => 'flight-school']);
        $city = Course::factory()->create(['slug' => 'city-operations']);
        $flyingChallenge = Challenge::factory()->for($flying)->create();
        $cityChallenge = Challenge::factory()->for($city)->create();

        $flyer = User::factory()->create(['name' => 'Ada']);
        $mapper = User::factory()->create(['name' => 'Grace']);

        $this->recordProgress($flyer, $flyingChallenge, points: 100, stars: 3);
        $this->recordProgress($mapper, $cityChallenge, points: 40, stars: 1);

        $response = $this->actingAs($flyer)->get(route('leaderboard', ['course' => 'city-operations']));

        $response->assertInertia(fn ($page) => $page
            ->where('courseSlug', 'city-operations')
            ->has('standings', 1)
            ->where('standings.0.name', 'Grace')
            ->where('standings.0.points', 40)
            ->where('pilotCount', 1)
            ->where('you', null));
    }

    public function test_an_unknown_course_slug_falls_back_to_the_overall_board(): void
    {
        $course = Course::factory()->create();
        $challenge = Challenge::factory()->for($course)->create();
        $pilot = User::factory()->create();

        $this->recordProgress($pilot, $challenge, points: 70, stars: 2);

        $response = $this->actingAs($pilot)->get(route('leaderboard', ['course' => 'no-such-course']));

        $response->assertInertia(fn ($page) => $page
            ->where('courseSlug', null)
            ->has('standings', 1));
    }

    public function test_unpublished_content_is_left_out_of_the_standings(): void
    {
        $course = Course::factory()->create();
        $retiredCourse = Course::factory()->unpublished()->create();
        $published = Challenge::factory()->for($course)->create();
        $retired = Challenge::factory()->for($course)->unpublished()->create();
        $inRetiredCourse = Challenge::factory()->for($retiredCourse)->create();

        $pilot = User::factory()->create();

        $this->recordProgress($pilot, $published, points: 30, stars: 1);
        $this->recordProgress($pilot, $retired, points: 100, stars: 3);
        $this->recordProgress($pilot, $inRetiredCourse, points: 100, stars: 3);

        $response = $this->actingAs($pilot)->get(route('leaderboard'));

        $response->assertInertia(fn ($page) => $page
            ->has('standings', 1)
            ->where('standings.0.points', 30)
            ->where('standings.0.stars', 1)
            ->where('standings.0.completed', 1));
    }

    public function test_a_pilot_outside_the_top_of_the_board_still_gets_their_own_row(): void
    {
        $course = Course::factory()->create();
        $challenge = Challenge::factory()->for($course)->create();

        $topPilots = LeaderboardController::TOP_PILOTS;

        for ($i = 0; $i < $topPilots; $i++) {
            $this->recordProgress(
                User::factory()->create(),
                $challenge,
                points: 100 - $i,
                stars: 3,
            );
        }

        $straggler = User::factory()->create(['name' => 'Grace']);
        $this->recordProgress($straggler, $challenge, points: 5, stars: 0);

        $response = $this->actingAs($straggler)->get(route('leaderboard'));

        $response->assertInertia(fn ($page) => $page
            ->has('standings', $topPilots)
            ->where('you.name', 'Grace')
            ->where('you.rank', $topPilots + 1)
            ->where('you.points', 5)
            ->where('you.isYou', true)
            ->where('pilotCount', $topPilots + 1));
    }

    public function test_a_pilot_who_has_not_flown_yet_has_no_standing(): void
    {
        $course = Course::factory()->create();
        Challenge::factory()->for($course)->create();

        $response = $this->actingAs(User::factory()->create())->get(route('leaderboard'));

        $response->assertInertia(fn ($page) => $page
            ->has('standings', 0)
            ->where('you', null)
            ->where('pilotCount', 0));
    }

    public function test_the_course_filter_lists_only_published_courses(): void
    {
        Course::factory()->create(['title' => 'Flight School', 'order' => 1]);
        Course::factory()->unpublished()->create(['title' => 'Secret Ops']);

        $response = $this->actingAs(User::factory()->create())->get(route('leaderboard'));

        $response->assertInertia(fn ($page) => $page
            ->has('courses', 1)
            ->where('courses.0.title', 'Flight School'));
    }

    private function recordProgress(User $user, Challenge $challenge, int $points, int $stars): void
    {
        UserChallengeProgress::factory()->completed()->create([
            'user_id' => $user->id,
            'challenge_id' => $challenge->id,
            'best_score' => $points,
            'stars' => $stars,
        ]);
    }
}
