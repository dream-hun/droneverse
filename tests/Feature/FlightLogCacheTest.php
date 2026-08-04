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
        $flightLog = app(FlightLog::class);

        $flightLog->summaryFor($user);

        $queries = $this->queriesDuring(fn () => $flightLog->summaryFor($user));

        $this->assertSame([], $queries, 'the summary aggregate ran again on a cached read');
    }

    public function test_recording_a_run_retires_every_cached_slice(): void
    {
        $user = $this->pilotWithRuns();
        $course = Course::first();
        $challenge = Challenge::first();
        $flightLog = app(FlightLog::class);

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
        $flightLog = app(FlightLog::class);

        $this->assertNull($flightLog->cohortFor($user, $challenge));

        $queries = $this->queriesDuring(fn () => $flightLog->cohortFor($user, $challenge));

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
        $flightLog = app(FlightLog::class);

        $flightLog->summaryFor($user);
        $flightLog->weakSpots($user);
        $flightLog->flownMissions($user);
        $flightLog->missionCurve($user, Challenge::firstOrFail());
        $flightLog->cohortFor($user, Challenge::firstOrFail());

        $generation = Cache::get('flight-log:generation', 0);

        $keys = [
            sprintf('flight-log:%s:summary:%d', $generation, $user->id),
            sprintf('flight-log:%s:weak-spots:%d:5', $generation, $user->id),
            sprintf('flight-log:%s:missions:%d', $generation, $user->id),
            sprintf('flight-log:%s:curve:%d:%d', $generation, Challenge::firstOrFail()->id, $user->id),
            sprintf('flight-log:%s:cohort:%d:%d', $generation, Challenge::firstOrFail()->id, $user->id),
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
        $flightLog = app(FlightLog::class);

        $this->assertNull(Cache::get('flight-log:generation'));

        $flightLog->forget();
        $this->assertSame(1, (int) Cache::get('flight-log:generation'));

        $flightLog->forget();
        $this->assertSame(2, (int) Cache::get('flight-log:generation'));
    }

    public function test_the_flight_log_and_the_leaderboard_do_not_share_a_generation(): void
    {
        // Two read models, two invalidation policies. A shared counter would
        // make either one's writes retire the other's cache.
        app(FlightLog::class)->forget();

        $this->assertSame(1, (int) Cache::get('flight-log:generation'));
        $this->assertNull(Cache::get('leaderboard:generation'));
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
     * Queries against the run table issued while running the callback.
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
                fn (array $query): bool => str_contains($query['raw_query'], 'challenge_runs'),
            ),
        ));
    }
}
