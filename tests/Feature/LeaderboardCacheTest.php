<?php

declare(strict_types=1);

use App\Models\Challenge;
use App\Models\Course;
use App\Models\User;
use App\Models\UserChallengeProgress;
use Illuminate\Support\Facades\DB;

test('a repeat view of the board does not re run the ranking', function (): void {
    $viewer = pilotWithProgress();

    $this->actingAs($viewer)->get(route('leaderboard'))->assertOk();

    DB::enableQueryLog();
    $this->actingAs($viewer)->get(route('leaderboard'))->assertOk();
    $queries = DB::getRawQueryLog();
    DB::disableQueryLog();

    $ranking = array_filter(
        $queries,
        fn (array $query): bool => str_contains($query['raw_query'], 'rank() over'),
    );

    $this->assertSame(
        [],
        $ranking,
        'the ranking aggregate ran again on a cached board',
    );
});

test('recording a run puts the pilot on the board immediately', function (): void {
    $course = Course::factory()->create();
    $challenge = Challenge::factory()->for($course)->create(['max_score' => 100]);

    $incumbent = User::factory()->create(['name' => 'Ada']);
    UserChallengeProgress::factory()->completed()->create([
        'user_id' => $incumbent->id,
        'challenge_id' => $challenge->id,
        'best_score' => 40,
    ]);

    $newcomer = User::factory()->create(['name' => 'Grace']);

    // Warm the cache on a board that does not know about Grace yet.
    $this->actingAs($newcomer)->get(route('leaderboard'))
        ->assertInertia(fn ($page) => $page->has('standings', 1));

    $this->actingAs($newcomer)->postJson(
        route('challenges.attempts.store', [$course, $challenge]),
        [
            'code' => 'async function main(drone) {}',
            'collisions' => 0,
            'photos' => [],
            'path' => [
                ['t' => 0, 'x' => 0, 'y' => 0.15, 'z' => 0],
                ['t' => 1, 'x' => 0, 'y' => 1.5, 'z' => 0],
                ['t' => 2, 'x' => 0, 'y' => 0.15, 'z' => 0],
            ],
        ],
    )->assertOk();

    $this->actingAs($newcomer)->get(route('leaderboard'))
        ->assertInertia(fn ($page) => $page
            ->has('standings', 2)
            ->where('standings.0.name', 'Grace')
            ->where('standings.0.points', 100)
            ->where('pilotCount', 2));
});

/**
 * The array store the suite runs on keeps the live object, so nothing
 * it caches is ever serialized. Every driver that could be deployed
 * does serialize, and reads back through `serializable_classes` —
 * `false` here, so no class survives the round trip. A board cached as
 * query rows therefore rendered once, on the miss that populated it,
 * and fatalled on every hit until the entry aged out.
 */
test('a cached board is readable on the configured cache driver', function (): void {
    config(['cache.default' => 'database']);

    $viewer = pilotWithProgress();

    // Populates the cache.
    $this->actingAs($viewer)->get(route('leaderboard'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('standings', 1));

    // Reads it back.
    $this->actingAs($viewer)->get(route('leaderboard'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('standings', 1)
            ->where('standings.0.isYou', true)
            ->where('you.isYou', true));
});

/**
 * The suite runs on the array store, which counts up from nothing when
 * asked to increment a key it has never seen. The database and
 * memcached stores do not — they refuse and write nothing — so a
 * generation counter that no run had ever created stayed at zero, every
 * bump was discarded, and the board went stale behind a cache the app
 * believed it was retiring. Deployed configuration picks the driver, so
 * this has to hold on the one production actually uses.
 */
test('recording a run retires the board on the configured cache driver', function (): void {
    config(['cache.default' => 'database']);

    $course = Course::factory()->create();
    $challenge = Challenge::factory()->for($course)->create(['max_score' => 100]);

    $incumbent = User::factory()->create(['name' => 'Ada']);
    UserChallengeProgress::factory()->completed()->create([
        'user_id' => $incumbent->id,
        'challenge_id' => $challenge->id,
        'best_score' => 40,
    ]);

    $newcomer = User::factory()->create(['name' => 'Grace']);

    $this->actingAs($newcomer)->get(route('leaderboard'))
        ->assertInertia(fn ($page) => $page->has('standings', 1));

    $this->actingAs($newcomer)->postJson(
        route('challenges.attempts.store', [$course, $challenge]),
        [
            'code' => 'async function main(drone) {}',
            'collisions' => 0,
            'photos' => [],
            'path' => [
                ['t' => 0, 'x' => 0, 'y' => 0.15, 'z' => 0],
                ['t' => 1, 'x' => 0, 'y' => 1.5, 'z' => 0],
                ['t' => 2, 'x' => 0, 'y' => 0.15, 'z' => 0],
            ],
        ],
    )->assertOk();

    $this->actingAs($newcomer)->get(route('leaderboard'))
        ->assertInertia(fn ($page) => $page
            ->has('standings', 2)
            ->where('standings.0.name', 'Grace'));
});

test('each course board is cached separately from the overall one', function (): void {
    $viewer = pilotWithProgress();
    $other = Course::factory()->create(['slug' => 'empty-course']);

    $this->actingAs($viewer)->get(route('leaderboard'))
        ->assertInertia(fn ($page) => $page->has('standings', 1));

    $this->actingAs($viewer)->get(route('leaderboard', ['course' => $other->slug]))
        ->assertInertia(fn ($page) => $page
            ->has('standings', 0)
            ->where('pilotCount', 0));
});

function pilotWithProgress(): User
{
    $course = Course::factory()->create();
    $challenge = Challenge::factory()->for($course)->create();
    $user = User::factory()->create();

    UserChallengeProgress::factory()->completed()->create([
        'user_id' => $user->id,
        'challenge_id' => $challenge->id,
    ]);

    return $user;
}
