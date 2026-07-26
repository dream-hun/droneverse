<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Challenge;
use App\Models\Course;
use App\Models\User;
use App\Models\UserChallengeProgress;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class LeaderboardCacheTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_repeat_view_of_the_board_does_not_re_run_the_ranking(): void
    {
        $viewer = $this->pilotWithProgress();

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
    }

    public function test_recording_a_run_puts_the_pilot_on_the_board_immediately(): void
    {
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
    }

    public function test_each_course_board_is_cached_separately_from_the_overall_one(): void
    {
        $viewer = $this->pilotWithProgress();
        $other = Course::factory()->create(['slug' => 'empty-course']);

        $this->actingAs($viewer)->get(route('leaderboard'))
            ->assertInertia(fn ($page) => $page->has('standings', 1));

        $this->actingAs($viewer)->get(route('leaderboard', ['course' => $other->slug]))
            ->assertInertia(fn ($page) => $page
                ->has('standings', 0)
                ->where('pilotCount', 0));
    }

    private function pilotWithProgress(): User
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
}
