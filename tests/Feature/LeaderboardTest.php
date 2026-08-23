<?php

declare(strict_types=1);

use Inertia\Testing\AssertableInertia;
use App\Http\Controllers\LeaderboardController;
use App\Models\Challenge;
use App\Models\Course;
use App\Models\User;
use App\Models\UserChallengeProgress;

test('guests are redirected to the login page', function (): void {
    $response = $this->get(route('leaderboard'));

    $response->assertRedirect(route('login'));
});

test('pilots are ranked by points', function (): void {
    $course = Course::factory()->create();
    $challenge = Challenge::factory()->for($course)->create();

    $leader = User::factory()->create(['name' => 'Ada']);
    $runnerUp = User::factory()->create(['name' => 'Grace']);

    recordProgress($leader, $challenge, points: 90, stars: 3);
    recordProgress($runnerUp, $challenge, points: 40, stars: 1);

    $response = $this->actingAs($runnerUp)->get(route('leaderboard'));

    $response->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
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
});

test('points are summed across every challenge', function (): void {
    $course = Course::factory()->create();
    $first = Challenge::factory()->for($course)->create();
    $second = Challenge::factory()->for($course)->create();

    $allRounder = User::factory()->create(['name' => 'Ada']);
    $specialist = User::factory()->create(['name' => 'Grace']);

    recordProgress($allRounder, $first, points: 60, stars: 2);
    recordProgress($allRounder, $second, points: 60, stars: 2);
    recordProgress($specialist, $first, points: 100, stars: 3);

    $response = $this->actingAs($allRounder)->get(route('leaderboard'));

    $response->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
        ->where('standings.0.name', 'Ada')
        ->where('standings.0.points', 120)
        ->where('standings.0.stars', 4)
        ->where('standings.0.completed', 2)
        ->where('standings.1.name', 'Grace')
        ->where('standings.1.points', 100));
});

test('stars then completions break a points tie', function (): void {
    $course = Course::factory()->create();
    $first = Challenge::factory()->for($course)->create();
    $second = Challenge::factory()->for($course)->create();

    $starred = User::factory()->create(['name' => 'Ada']);
    $unstarred = User::factory()->create(['name' => 'Grace']);

    recordProgress($starred, $first, points: 100, stars: 3);
    recordProgress($unstarred, $first, points: 50, stars: 1);
    recordProgress($unstarred, $second, points: 50, stars: 1);

    $response = $this->actingAs($starred)->get(route('leaderboard'));

    $response->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
        ->where('standings.0.name', 'Ada')
        ->where('standings.0.rank', 1)
        ->where('standings.1.name', 'Grace')
        ->where('standings.1.rank', 2));
});

test('pilots level on every metric share a rank', function (): void {
    $course = Course::factory()->create();
    $challenge = Challenge::factory()->for($course)->create();

    $first = User::factory()->create(['name' => 'Ada']);
    $second = User::factory()->create(['name' => 'Grace']);
    $third = User::factory()->create(['name' => 'Katherine']);

    recordProgress($first, $challenge, points: 80, stars: 2);
    recordProgress($second, $challenge, points: 80, stars: 2);
    recordProgress($third, $challenge, points: 10, stars: 0);

    $response = $this->actingAs($first)->get(route('leaderboard'));

    $response->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
        ->where('standings.0.rank', 1)
        ->where('standings.1.rank', 1)
        ->where('standings.2.name', 'Katherine')
        ->where('standings.2.rank', 3));
});

test('the board can be scoped to a single course', function (): void {
    $flying = Course::factory()->create(['slug' => 'flight-school']);
    $city = Course::factory()->create(['slug' => 'city-operations']);
    $flyingChallenge = Challenge::factory()->for($flying)->create();
    $cityChallenge = Challenge::factory()->for($city)->create();

    $flyer = User::factory()->create(['name' => 'Ada']);
    $mapper = User::factory()->create(['name' => 'Grace']);

    recordProgress($flyer, $flyingChallenge, points: 100, stars: 3);
    recordProgress($mapper, $cityChallenge, points: 40, stars: 1);

    $response = $this->actingAs($flyer)->get(route('leaderboard', ['course' => 'city-operations']));

    $response->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
        ->where('courseSlug', 'city-operations')
        ->has('standings', 1)
        ->where('standings.0.name', 'Grace')
        ->where('standings.0.points', 40)
        ->where('pilotCount', 1)
        ->where('you', null));
});

test('an unknown course slug falls back to the overall board', function (): void {
    $course = Course::factory()->create();
    $challenge = Challenge::factory()->for($course)->create();
    $pilot = User::factory()->create();

    recordProgress($pilot, $challenge, points: 70, stars: 2);

    $response = $this->actingAs($pilot)->get(route('leaderboard', ['course' => 'no-such-course']));

    $response->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
        ->where('courseSlug', null)
        ->has('standings', 1));
});

test('unpublished content is left out of the standings', function (): void {
    $course = Course::factory()->create();
    $retiredCourse = Course::factory()->unpublished()->create();
    $published = Challenge::factory()->for($course)->create();
    $retired = Challenge::factory()->for($course)->unpublished()->create();
    $inRetiredCourse = Challenge::factory()->for($retiredCourse)->create();

    $pilot = User::factory()->create();

    recordProgress($pilot, $published, points: 30, stars: 1);
    recordProgress($pilot, $retired, points: 100, stars: 3);
    recordProgress($pilot, $inRetiredCourse, points: 100, stars: 3);

    $response = $this->actingAs($pilot)->get(route('leaderboard'));

    $response->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
        ->has('standings', 1)
        ->where('standings.0.points', 30)
        ->where('standings.0.stars', 1)
        ->where('standings.0.completed', 1));
});

test('a pilot outside the top of the board still gets their own row', function (): void {
    $course = Course::factory()->create();
    $challenge = Challenge::factory()->for($course)->create();

    $topPilots = LeaderboardController::TOP_PILOTS;

    for ($i = 0; $i < $topPilots; $i++) {
        recordProgress(
            User::factory()->create(),
            $challenge,
            points: 100 - $i,
            stars: 3,
        );
    }

    $straggler = User::factory()->create(['name' => 'Grace']);
    recordProgress($straggler, $challenge, points: 5, stars: 0);

    $response = $this->actingAs($straggler)->get(route('leaderboard'));

    $response->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
        ->has('standings', $topPilots)
        ->where('you.name', 'Grace')
        ->where('you.rank', $topPilots + 1)
        ->where('you.points', 5)
        ->where('you.isYou', true)
        ->where('pilotCount', $topPilots + 1));
});

test('a pilot who has not flown yet has no standing', function (): void {
    $course = Course::factory()->create();
    Challenge::factory()->for($course)->create();

    $response = $this->actingAs(User::factory()->create())->get(route('leaderboard'));

    $response->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
        ->has('standings', 0)
        ->where('you', null)
        ->where('pilotCount', 0));
});

test('the course filter lists only published courses', function (): void {
    Course::factory()->create(['title' => 'Flight School', 'order' => 1]);
    Course::factory()->unpublished()->create(['title' => 'Secret Ops']);

    $response = $this->actingAs(User::factory()->create())->get(route('leaderboard'));

    $response->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
        ->has('courses', 1)
        ->where('courses.0.title', 'Flight School'));
});

function recordProgress(User $user, Challenge $challenge, int $points, int $stars): void
{
    UserChallengeProgress::factory()->completed()->create([
        'user_id' => $user->id,
        'challenge_id' => $challenge->id,
        'best_score' => $points,
        'stars' => $stars,
    ]);
}
