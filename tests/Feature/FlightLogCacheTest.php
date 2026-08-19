<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Challenge;
use App\Models\ChallengeRun;
use App\Models\Course;
use App\Models\User;
use App\Queries\FlightLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * What the flight log's cache is required to do.
 *
 * The same four properties App\Queries\Support\SliceCache exists to hold,
 * asserted against the read model that uses it — these were each a real
 * defect on the leaderboard before the mechanism was shared, and a second
 * caller is exactly where they would come back.
 */
final class FlightLogCacheTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_repeat_read_does_not_re_run_the_aggregate(): void
    {
        $user = $this->pilotWithRuns();
        $flightLog = resolve(FlightLog::class);

        $flightLog->summaryFor($user);

        $queries = $this->queriesDuring(fn () => $flightLog->summaryFor($user));

        $this->assertSame([], $queries, 'the summary aggregate ran again on a cached read');
    }

    public function test_recording_a_run_retires_every_cached_slice(): void
    {
        $user = $this->pilotWithRuns();
        $course = Course::query()->first();
        $challenge = Challenge::query()->first();
        $flightLog = resolve(FlightLog::class);

        $this->assertSame(2, $flightLog->summaryFor($user)['runs']);

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

        $this->assertSame(
            3,
            $flightLog->summaryFor($user)['runs'],
            'the summary was served from a cache the new run should have retired',
        );
    }

    public function test_a_pilot_with_no_standing_on_a_mission_does_not_re_run_the_cohort(): void
    {
        // A null cohort is a legitimate answer — this pilot has not flown the
        // mission — and a bare null in the cache is indistinguishable from a
        // miss. Unwrapped, exactly these pilots paid for the aggregate on
        // every single page view.
        $user = User::factory()->create();
        $challenge = Challenge::factory()->create();
        $flightLog = resolve(FlightLog::class);

        $this->assertNull($flightLog->cohortFor($user, $challenge));

        $queries = $this->queriesDuring(fn (): null => $flightLog->cohortFor($user, $challenge));

        $this->assertSame([], $queries, 'a null cohort was treated as a cache miss');
    }

    public function test_nothing_with_a_class_crosses_the_cache_boundary(): void
    {
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
        $user = $this->pilotWithRuns();
        $challenge = Challenge::query()->firstOrFail();
        $flightLog = resolve(FlightLog::class);

        $flightLog->summaryFor($user);
        $flightLog->weakSpots($user);
        $flightLog->flownMissions($user);
        $flightLog->missionCurve($user, $challenge);
        $flightLog->cohortFor($user, $challenge);

        $pilot = sprintf('pilot:%d=%d', $user->id, $this->generation("pilot:{$user->id}"));
        $flight = sprintf(
            'flight:%d:%d=%d',
            $user->id,
            $challenge->id,
            $this->generation("flight:{$user->id}:{$challenge->id}"),
        );
        $mission = sprintf('mission:%d=%d', $challenge->id, $this->generation("mission:{$challenge->id}"));

        $keys = [
            sprintf('flight-log:%s:summary:%d', $pilot, $user->id),
            sprintf('flight-log:%s:weak-spots:%d:5', $pilot, $user->id),
            sprintf('flight-log:%s:missions:%d', $pilot, $user->id),
            sprintf('flight-log:%s:curve:%d:%d', $flight, $challenge->id, $user->id),
            sprintf('flight-log:%s:cohort:%d:%d', $mission, $challenge->id, $user->id),
        ];

        foreach ($keys as $key) {
            $cached = Cache::get($key);

            $this->assertIsArray($cached, "nothing was cached under {$key}");
            $this->assertArrayHasKey('value', $cached, "the slice at {$key} was stored bare");
            $this->assertStringNotContainsString(
                'O:',
                serialize($cached),
                "the slice at {$key} carries an object across the cache boundary",
            );
        }
    }

    public function test_the_generation_counter_is_seeded_before_it_is_bumped(): void
    {
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

        $this->assertNull(Cache::get("flight-log:generation:pilot:{$user->id}"));

        $flightLog->forget($user, $challenge);
        $this->assertSame(1, $this->generation("pilot:{$user->id}"));

        $flightLog->forget($user, $challenge);
        $this->assertSame(2, $this->generation("pilot:{$user->id}"));
    }

    public function test_seeding_a_generation_asks_the_store_for_an_atomic_add(): void
    {
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

        Cache::spy();

        resolve(FlightLog::class)->forget($user, $challenge);

        Cache::shouldHaveReceived('add')
            ->withArgs(fn (string $key, int $generation, ?int $ttl): bool => $ttl !== null)
            ->times(3);
    }

    public function test_a_run_leaves_another_pilots_cached_slices_alone(): void
    {
        /*
         * The reason the counters are per scope. A single counter per read
         * model meant one pilot landing a run retired every cached slice on
         * the page, almost all of which are per-pilot and could not have
         * been affected by it.
         */
        $user = $this->pilotWithRuns();
        $challenge = Challenge::query()->firstOrFail();
        $bystander = $this->pilotWithRuns();
        $flightLog = resolve(FlightLog::class);

        $flightLog->summaryFor($bystander);

        $flightLog->forget($user, $challenge);

        $queries = $this->queriesDuring(fn () => $flightLog->summaryFor($bystander));

        $this->assertSame(
            [],
            $queries,
            "another pilot's run retired this pilot's summary",
        );
    }

    public function test_a_run_leaves_the_pilots_curve_on_other_missions_alone(): void
    {
        $user = $this->pilotWithRuns();
        $flown = Challenge::query()->firstOrFail();
        $elsewhere = Challenge::factory()->create();
        $flightLog = resolve(FlightLog::class);

        $flightLog->missionCurve($user, $elsewhere);

        $flightLog->forget($user, $flown);

        $queries = $this->queriesDuring(fn () => $flightLog->missionCurve($user, $elsewhere));

        $this->assertSame(
            [],
            $queries,
            'a run on one mission retired the curve on another',
        );
    }

    public function test_a_run_retires_every_slice_it_could_have_moved(): void
    {
        // The other half of the contract: narrowing invalidation must not
        // leave a slice that the run really did move being served stale.
        $user = $this->pilotWithRuns();
        $challenge = Challenge::query()->firstOrFail();
        $rival = $this->pilot($challenge);
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
            $this->assertNotSame(
                [],
                $this->queriesDuring($read),
                "the {$slice} was served from a cache the run should have retired",
            );
        }
    }

    public function test_the_flight_log_and_the_leaderboard_do_not_share_a_generation(): void
    {
        // Two read models, two invalidation policies. A shared counter would
        // make either one's writes retire the other's cache.
        $user = User::factory()->create();
        $challenge = Challenge::factory()->create();

        resolve(FlightLog::class)->forget($user, $challenge);

        $this->assertSame(1, $this->generation("pilot:{$user->id}"));
        $this->assertNull(Cache::get('leaderboard:generation:all'));
    }

    /**
     * Where a scope's generation counter currently stands.
     */
    private function generation(string $scope): int
    {
        return (int) Cache::get("flight-log:generation:{$scope}", 0);
    }

    /**
     * A second pilot with runs on an existing mission.
     */
    private function pilot(Challenge $challenge): User
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
    private function pilotWithRuns(): User
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
    private function queriesDuring(callable $callback): array
    {
        DB::enableQueryLog();
        $callback();
        $queries = DB::getRawQueryLog();
        DB::disableQueryLog();

        return array_values(array_map(
            fn (array $query): string => $query['raw_query'],
            array_filter(
                $queries,
                fn (array $query): bool => str_contains($query['raw_query'], 'challenge_runs')
                    || str_contains($query['raw_query'], 'pilot_mission_stats'),
            ),
        ));
    }
}
