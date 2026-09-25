<?php

declare(strict_types=1);

use App\Models\Challenge;
use App\Models\ChallengeRun;
use App\Models\Course;
use App\Models\User;
use App\Queries\FlightLog;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Pest\Matchers\Any;

/*
 * What the flight log's cache is required to do.
 *
 * The same four properties App\Queries\Support\SliceCache exists to hold,
 * asserted against the read model that uses it — these were each a real
 * defect on the leaderboard before the mechanism was shared, and a second
 * caller is exactly where they would come back.
 */

test('a repeat read does not re run the aggregate', function (): void {
    $user = pilotWithRuns();
    $flightLog = resolve(FlightLog::class);

    $flightLog->summaryFor($user);

    $queries = queriesDuring(fn () => $flightLog->summaryFor($user));

    expect($queries)->toBe([], 'the summary aggregate ran again on a cached read');
});

test('recording a run retires every cached slice', function (): void {
    $user = pilotWithRuns();
    $course = Course::query()->first();
    $challenge = Challenge::query()->first();
    $flightLog = resolve(FlightLog::class);

    expect($flightLog->summaryFor($user)['runs'])->toBe(2);

    $this->actingAs($user)->postJson(
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

    expect($flightLog->summaryFor($user)['runs'])->toBe(3, 'the summary was served from a cache the new run should have retired');
});

test('a pilot with no standing on a mission does not re run the cohort', function (): void {
    // A null cohort is a legitimate answer — this pilot has not flown the
    // mission — and a bare null in the cache is indistinguishable from a
    // miss. Unwrapped, exactly these pilots paid for the aggregate on
    // every single page view.
    $user = User::factory()->create();
    $challenge = Challenge::factory()->create();
    $flightLog = resolve(FlightLog::class);

    expect($flightLog->cohortFor($user, $challenge))->toBeNull();

    $queries = queriesDuring(fn (): ?array => $flightLog->cohortFor($user, $challenge));

    expect($queries)->toBe([], 'a null cohort was treated as a cache miss');
});

test('nothing with a class crosses the cache boundary', function (): void {
    /*
     * Every serializing store unserializes through
     * `cache.serializable_classes`, left at the framework default of
     * false so a leaked APP_KEY cannot become a gadget chain. A cached
     * query row therefore comes back as __PHP_Incomplete_Class and fatals
     * on first use — the page renders on the miss that populates the
     * cache and throws on every hit until it expires. The array store
     * this suite uses keeps the live object and cannot see it, so the
     * assertion is made on the shape of what was stored.
     */
    $user = pilotWithRuns();
    $challenge = Challenge::query()->firstOrFail();
    $flightLog = resolve(FlightLog::class);

    $flightLog->summaryFor($user);
    $flightLog->weakSpots($user);
    $flightLog->flownMissions($user);
    $flightLog->missionCurve($user, $challenge);
    $flightLog->cohortFor($user, $challenge);

    $pilot = sprintf('pilot:%d=%d', $user->id, generation("pilot:{$user->id}"));
    $flight = sprintf(
        'flight:%d:%d=%d',
        $user->id,
        $challenge->id,
        generation("flight:{$user->id}:{$challenge->id}"),
    );
    $mission = sprintf('mission:%d=%d', $challenge->id, generation("mission:{$challenge->id}"));

    $keys = [
        sprintf('flight-log:%s:summary:%d', $pilot, $user->id),
        sprintf('flight-log:%s:weak-spots:%d:5', $pilot, $user->id),
        sprintf('flight-log:%s:missions:%d', $pilot, $user->id),
        sprintf('flight-log:%s:curve:%d:%d', $flight, $challenge->id, $user->id),
        sprintf('flight-log:%s:cohort:%d:%d', $mission, $challenge->id, $user->id),
    ];

    foreach ($keys as $key) {
        $cached = Cache::get($key);

        expect($cached)->toBeArray("nothing was cached under {$key}");
        expect($cached)->toHaveKey('value', new Any, "the slice at {$key} was stored bare");
        $this->assertStringNotContainsString(
            'O:',
            serialize($cached),
            "the slice at {$key} carries an object across the cache boundary",
        );
    }
});

test('the generation counter is seeded before it is bumped', function (): void {
    /*
     * Only some drivers count up from zero on `increment` against an
     * absent key; the database and memcached stores write nothing and
     * return false. Without the seed, a generation no run had ever
     * created stayed pinned at zero and every invalidation silently did
     * nothing.
     */
    $user = User::factory()->create();
    $challenge = Challenge::factory()->create();
    $flightLog = resolve(FlightLog::class);

    expect(Cache::get("flight-log:generation:pilot:{$user->id}"))->toBeNull();

    $flightLog->forget($user, $challenge);
    expect(generation("pilot:{$user->id}"))->toBe(1);

    $flightLog->forget($user, $challenge);
    expect(generation("pilot:{$user->id}"))->toBe(2);
});

test('seeding a generation asks the store for an atomic add', function (): void {
    /*
     * The seed above is only worth anything if it is atomic, and whether
     * it is comes down to one argument. `Illuminate\Cache\Repository::add()`
     * hands the work to the store's own atomic `add` *only when given a
     * TTL*; without one it falls back to a `get()` followed by an
     * unconditional `put()`. Two writers can then both find the counter
     * absent and the second's `put` resets a generation the first has
     * already bumped past — every slice built against the old one comes
     * back to life for the rest of its TTL, which is the one thing the
     * counter exists to prevent.
     *
     * Asserted on the call rather than on the outcome because the losing
     * interleaving needs two processes, and the suite has one. What can
     * be pinned here is that the store is asked for the atomic path at
     * all.
     */
    $user = User::factory()->create();
    $challenge = Challenge::factory()->create();

    $cache = Cache::spy();

    resolve(FlightLog::class)->forget($user, $challenge);

    $cache->shouldHaveReceived('add')
        ->withArgs(fn (string $key, int $generation, ?int $ttl): bool => $ttl !== null)
        ->times(3);
});

test('a run leaves another pilots cached slices alone', function (): void {
    /*
     * The reason the counters are per scope. A single counter per read
     * model meant one pilot landing a run retired every cached slice on
     * the page, almost all of which are per-pilot and could not have
     * been affected by it.
     */
    $user = pilotWithRuns();
    $challenge = Challenge::query()->firstOrFail();
    $bystander = pilotWithRuns();
    $flightLog = resolve(FlightLog::class);

    $flightLog->summaryFor($bystander);

    $flightLog->forget($user, $challenge);

    $queries = queriesDuring(fn () => $flightLog->summaryFor($bystander));

    expect($queries)->toBe([], "another pilot's run retired this pilot's summary");
});

test('a run leaves the pilots curve on other missions alone', function (): void {
    $user = pilotWithRuns();
    $flown = Challenge::query()->firstOrFail();
    $elsewhere = Challenge::factory()->create();
    $flightLog = resolve(FlightLog::class);

    $flightLog->missionCurve($user, $elsewhere);

    $flightLog->forget($user, $flown);

    $queries = queriesDuring(fn () => $flightLog->missionCurve($user, $elsewhere));

    expect($queries)->toBe([], 'a run on one mission retired the curve on another');
});

test('a run retires every slice it could have moved', function (): void {
    // The other half of the contract: narrowing invalidation must not
    // leave a slice that the run really did move being served stale.
    $user = pilotWithRuns();
    $challenge = Challenge::query()->firstOrFail();
    $rival = flightLogPilot($challenge);
    $flightLog = resolve(FlightLog::class);

    $flightLog->summaryFor($user);
    $flightLog->weakSpots($user);
    $flightLog->flownMissions($user);
    $flightLog->missionCurve($user, $challenge);
    // Held by a different pilot, and still moved by this run: the cohort
    // is a statement about everyone who has flown the mission.
    $flightLog->cohortFor($rival, $challenge);

    $flightLog->forget($user, $challenge);

    foreach (
        [
            'summary' => fn () => $flightLog->summaryFor($user),
            'weak spots' => fn () => $flightLog->weakSpots($user),
            'flown missions' => fn () => $flightLog->flownMissions($user),
            'curve' => fn () => $flightLog->missionCurve($user, $challenge),
            'cohort' => fn () => $flightLog->cohortFor($rival, $challenge),
        ] as $slice => $read
    ) {
        expect(queriesDuring($read))->not->toBe([], "the {$slice} was served from a cache the run should have retired");
    }
});

test('the flight log and the leaderboard do not share a generation', function (): void {
    // Two read models, two invalidation policies. A shared counter would
    // make either one's writes retire the other's cache.
    $user = User::factory()->create();
    $challenge = Challenge::factory()->create();

    resolve(FlightLog::class)->forget($user, $challenge);

    expect(generation("pilot:{$user->id}"))->toBe(1);
    expect(Cache::get('leaderboard:generation:all'))->toBeNull();
});

/**
 * Where a scope's generation counter currently stands.
 */
function generation(string $scope): int
{
    return Cache::integer("flight-log:generation:{$scope}", 0);
}

/**
 * A second pilot with runs on an existing mission.
 */
function flightLogPilot(Challenge $challenge): User
{
    $user = User::factory()->create();

    ChallengeRun::factory()->count(2)->create([
        'user_id' => $user->id,
        'challenge_id' => $challenge->id,
    ]);

    return $user;
}

/**
 * A pilot with two runs on one published mission.
 */
function pilotWithRuns(): User
{
    $user = User::factory()->create();
    $course = Course::factory()->create();
    $challenge = Challenge::factory()->for($course)->create();

    ChallengeRun::factory()->count(2)->create([
        'user_id' => $user->id,
        'challenge_id' => $challenge->id,
    ]);

    return $user;
}

/**
 * Queries against the tables the log is built from, during the callback.
 *
 * Both of them: the curve still reads the runs themselves, and every
 * other slice reads the rollup over them. A filter naming only one would
 * quietly stop noticing the slices that read the other.
 *
 * @return array<int, string>
 */
function queriesDuring(callable $callback): array
{
    DB::enableQueryLog();
    $callback();
    $queries = array_filter(array_column(DB::getRawQueryLog(), 'raw_query'), is_string(...));
    DB::disableQueryLog();

    return array_values(array_filter(
        $queries,
        fn (string $query): bool => str_contains($query, 'challenge_runs')
            || str_contains($query, 'pilot_mission_stats'),
    ));
}
