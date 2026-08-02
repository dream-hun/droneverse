<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Challenge;
use App\Models\Course;
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

    public function test_forgetting_the_board_retires_a_cached_slice(): void
    {
        $challenge = $this->challenge();
        $ada = $this->pilot('Ada', $challenge, points: 40);

        $leaderboard = resolve(Leaderboard::class);

        $this->assertSame(1, $leaderboard->rankedPilotCount());

        $this->pilot('Grace', $challenge, points: 90);

        // Still the cached answer: nothing has told the board to move.
        $this->assertSame(1, $leaderboard->rankedPilotCount());

        $leaderboard->forget();

        $this->assertSame(2, $leaderboard->rankedPilotCount());
        $this->assertSame('Grace', $leaderboard->standings($ada)->first()['name']);
    }

    public function test_it_counts_only_playable_content_towards_a_pilots_totals(): void
    {
        $pilot = User::factory()->create();

        $this->progress($pilot, $this->challenge(), points: 30);
        $this->progress($pilot, $this->challenge(published: false), points: 70);

        $leaderboard = resolve(Leaderboard::class);

        $this->assertSame(['completed' => 1, 'stars' => 3], $leaderboard->statsFor($pilot));
        $this->assertSame([1], $leaderboard->completedCountsByCourse($pilot)->values()->all());
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
