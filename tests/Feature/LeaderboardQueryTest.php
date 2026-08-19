<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Actions\SelectMissionDrone;
use App\Models\Challenge;
use App\Models\Course;
use App\Models\DroneModel;
use App\Models\User;
use App\Models\UserChallengeProgress;
use App\Queries\Leaderboard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The board read model, exercised directly rather than through a page.
 *
 * {@see LeaderboardCacheTest} drives the same code over HTTP and is the
 * behavioural net for what a pilot actually sees. These cover the seam
 * itself: that the read model stands on its own away from the entity it
 * ranks, and answers the same way when a controller is not holding it.
 */
final class LeaderboardQueryTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_resolves_from_the_container(): void
    {
        $this->assertInstanceOf(Leaderboard::class, resolve(Leaderboard::class));
    }

    public function test_it_ranks_pilots_by_points_without_a_request(): void
    {
        $challenge = $this->challenge();

        $ada = $this->pilot('Ada', $challenge, points: 40);
        $this->pilot('Grace', $challenge, points: 90);

        $standings = resolve(Leaderboard::class)->standings($ada);

        $this->assertSame(['Grace', 'Ada'], $standings->pluck('name')->all());
        $this->assertSame([1, 2], $standings->pluck('rank')->all());
        $this->assertSame([false, true], $standings->pluck('isYou')->all());
    }

    public function test_it_reports_a_pilot_who_placed_outside_the_listed_page(): void
    {
        $challenge = $this->challenge();

        $this->pilot('Grace', $challenge, points: 90);
        $ada = $this->pilot('Ada', $challenge, points: 40);

        $leaderboard = resolve(Leaderboard::class);

        $this->assertSame([], $leaderboard->standings($ada, limit: 1)
            ->where('isYou', true)
            ->all());

        $standing = $leaderboard->standingFor($ada);

        $this->assertSame(2, $standing['rank']);
        $this->assertTrue($standing['isYou']);
    }

    public function test_a_pilot_who_has_not_flown_has_no_standing(): void
    {
        $this->challenge();

        $this->assertNull(
            resolve(Leaderboard::class)->standingFor(User::factory()->create()),
        );
    }

    public function test_writing_progress_retires_the_cached_board(): void
    {
        /*
         * No explicit `forgetCourse` here, which is the point. The board is
         * read from the rollup that App\Actions\RollUpCourseTotals maintains,
         * so the write that moves the rollup is the write that has to retire
         * the board — otherwise the cache stays right only for as long as
         * every caller remembers to say so, and one that forgets (picking an
         * airframe, say) leaves a board that is wrong for its whole TTL.
         */
        $challenge = $this->challenge();
        $ada = $this->pilot('Ada', $challenge, points: 40);

        $leaderboard = resolve(Leaderboard::class);

        $this->assertSame(1, $leaderboard->rankedPilotCount());

        $this->pilot('Grace', $challenge, points: 90);

        $this->assertSame(2, $leaderboard->rankedPilotCount());
        $this->assertSame('Grace', $leaderboard->standings($ada)->first()['name']);
    }

    public function test_choosing_an_airframe_retires_the_board_it_puts_a_pilot_on(): void
    {
        /*
         * The caller this was actually wrong for. Picking a drone writes a
         * progress row at its defaults, which is enough to earn a place on
         * the board — and nothing on that path ever called `forgetCourse`,
         * so the pilot was ranked in the table and absent from the cached
         * standings for the next five minutes.
         */
        $challenge = $this->challenge();
        $this->pilot('Ada', $challenge, points: 40);

        $leaderboard = resolve(Leaderboard::class);

        $this->assertSame(1, $leaderboard->rankedPilotCount());

        resolve(SelectMissionDrone::class)->handle(
            User::factory()->create(['name' => 'Grace']),
            $challenge,
            DroneModel::query()->firstOrFail(),
        );

        $this->assertSame(2, $leaderboard->rankedPilotCount());
    }

    public function test_a_run_in_one_course_leaves_another_courses_board_cached(): void
    {
        /*
         * Why the generation counter is per board. With one counter for the
         * whole read model, a run anywhere retired every course's board at
         * once, so the busiest course's traffic decided how often the
         * quietest one paid for its own ranking aggregate.
         */
        $flown = $this->challenge();
        $elsewhere = $this->challenge();
        $ada = $this->pilot('Ada', $elsewhere, points: 40);

        $leaderboard = resolve(Leaderboard::class);

        // Both boards cached, each answering for one pilot.
        $this->assertSame(1, $leaderboard->rankedPilotCount($elsewhere->course));
        $this->assertSame(1, $leaderboard->rankedPilotCount());

        // A pilot earns a place in the other course entirely.
        $this->pilot('Grace', $flown, points: 90);

        $this->assertSame(
            1,
            $leaderboard->rankedPilotCount($elsewhere->course),
            "a run in one course retired another course's cached board",
        );

        // And the overall board, which that run really could have moved, did
        // go: narrowing invalidation must not leave a stale slice standing.
        $this->assertSame(2, $leaderboard->rankedPilotCount());
        $this->assertSame('Grace', $leaderboard->standings($ada)->first()['name']);
    }

    public function test_it_counts_only_playable_content_towards_a_pilots_totals(): void
    {
        $pilot = User::factory()->create();

        $live = $this->challenge();
        $this->progress($pilot, $live, points: 30);
        $this->progress($pilot, $this->challenge(published: false), points: 70);

        $leaderboard = resolve(Leaderboard::class);

        $this->assertSame(['completed' => 1, 'stars' => 3], $leaderboard->statsFor($pilot));

        // Keyed by course id, which is how the catalogue cards look their own
        // count up. A count returned under any other key reads as zero on
        // every card rather than failing.
        $this->assertSame(
            [$live->course_id => 1],
            $leaderboard->completedCountsByCourse($pilot)->all(),
        );
    }

    private function challenge(bool $published = true): Challenge
    {
        $course = Course::factory()->create(['is_published' => $published]);

        return Challenge::factory()->for($course)->create([
            'max_score' => 100,
            'is_published' => $published,
        ]);
    }

    private function pilot(string $name, Challenge $challenge, int $points): User
    {
        $pilot = User::factory()->create(['name' => $name]);
        $this->progress($pilot, $challenge, $points);

        return $pilot;
    }

    private function progress(User $pilot, Challenge $challenge, int $points): void
    {
        UserChallengeProgress::factory()->completed()->create([
            'user_id' => $pilot->id,
            'challenge_id' => $challenge->id,
            'best_score' => $points,
            'stars' => 3,
        ]);
    }
}
