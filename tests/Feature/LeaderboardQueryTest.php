<?php

declare(strict_types=1);

use App\Actions\SelectMissionDrone;
use App\Models\Challenge;
use App\Models\Course;
use App\Models\DroneModel;
use App\Models\User;
use App\Models\UserChallengeProgress;
use App\Queries\Leaderboard;

/*
 * The board read model, exercised directly rather than through a page.
 *
 * {@see LeaderboardCacheTest} drives the same code over HTTP and is the
 * behavioural net for what a pilot actually sees. These cover the seam
 * itself: that the read model stands on its own away from the entity it
 * ranks, and answers the same way when a controller is not holding it.
 */

test('it resolves from the container', function (): void {
    expect(fn (): Leaderboard => resolve(Leaderboard::class))->not->toThrow(Throwable::class);
});

test('it ranks pilots by points without a request', function (): void {
    $challenge = challenge();

    $ada = leaderboardPilot('Ada', $challenge, points: 40);
    leaderboardPilot('Grace', $challenge, points: 90);

    $standings = resolve(Leaderboard::class)->standings($ada);

    expect($standings->pluck('name')->all())->toBe(['Grace', 'Ada']);
    expect($standings->pluck('rank')->all())->toBe([1, 2]);
    expect($standings->pluck('isYou')->all())->toBe([false, true]);
});

test('it reports a pilot who placed outside the listed page', function (): void {
    $challenge = challenge();

    leaderboardPilot('Grace', $challenge, points: 90);
    $ada = leaderboardPilot('Ada', $challenge, points: 40);

    $leaderboard = resolve(Leaderboard::class);

    expect($leaderboard->standings($ada, limit: 1)->where('isYou', true)->all())->toBe([]);

    $standing = $leaderboard->standingFor($ada);

    expect($standing['rank'] ?? null)->toBe(2);
    expect($standing['isYou'] ?? null)->toBeTrue();
});

test('a pilot who has not flown has no standing', function (): void {
    challenge();

    expect(resolve(Leaderboard::class)->standingFor(User::factory()->create()))->toBeNull();
});

test('writing progress retires the cached board', function (): void {
    /*
     * No explicit `forgetCourse` here, which is the point. The board is
     * read from the rollup that App\Actions\RollUpCourseTotals maintains,
     * so the write that moves the rollup is the write that has to retire
     * the board — otherwise the cache stays right only for as long as
     * every caller remembers to say so, and one that forgets (picking an
     * airframe, say) leaves a board that is wrong for its whole TTL.
     */
    $challenge = challenge();
    $ada = leaderboardPilot('Ada', $challenge, points: 40);

    $leaderboard = resolve(Leaderboard::class);

    expect($leaderboard->rankedPilotCount())->toBe(1);

    leaderboardPilot('Grace', $challenge, points: 90);

    expect($leaderboard->rankedPilotCount())->toBe(2);
    expect($leaderboard->standings($ada)->value('name'))->toBe('Grace');
});

test('choosing an airframe retires the board it puts a pilot on', function (): void {
    /*
     * The caller this was actually wrong for. Picking a drone writes a
     * progress row at its defaults, which is enough to earn a place on
     * the board — and nothing on that path ever called `forgetCourse`,
     * so the pilot was ranked in the table and absent from the cached
     * standings for the next five minutes.
     */
    $challenge = challenge();
    leaderboardPilot('Ada', $challenge, points: 40);

    $leaderboard = resolve(Leaderboard::class);

    expect($leaderboard->rankedPilotCount())->toBe(1);

    resolve(SelectMissionDrone::class)->handle(
        User::factory()->create(['name' => 'Grace']),
        $challenge,
        DroneModel::query()->firstOrFail(),
    );

    expect($leaderboard->rankedPilotCount())->toBe(2);
});

test('a run in one course leaves another courses board cached', function (): void {
    /*
     * Why the generation counter is per board. With one counter for the
     * whole read model, a run anywhere retired every course's board at
     * once, so the busiest course's traffic decided how often the
     * quietest one paid for its own ranking aggregate.
     */
    $flown = challenge();
    $elsewhere = challenge();
    $ada = leaderboardPilot('Ada', $elsewhere, points: 40);

    $leaderboard = resolve(Leaderboard::class);

    // Both boards cached, each answering for one pilot.
    expect($leaderboard->rankedPilotCount($elsewhere->course))->toBe(1);
    expect($leaderboard->rankedPilotCount())->toBe(1);

    // A pilot earns a place in the other course entirely.
    leaderboardPilot('Grace', $flown, points: 90);

    expect($leaderboard->rankedPilotCount($elsewhere->course))
        ->toBe(1, "a run in one course retired another course's cached board");

    // And the overall board, which that run really could have moved, did
    // go: narrowing invalidation must not leave a stale slice standing.
    expect($leaderboard->rankedPilotCount())->toBe(2);
    expect($leaderboard->standings($ada)->value('name'))->toBe('Grace');
});

test('it counts only playable content towards a pilots totals', function (): void {
    $pilot = User::factory()->create();

    $live = challenge();
    leaderboardProgress($pilot, $live, points: 30);
    leaderboardProgress($pilot, challenge(published: false), points: 70);

    $leaderboard = resolve(Leaderboard::class);

    expect($leaderboard->statsFor($pilot))->toBe(['completed' => 1, 'stars' => 3]);

    // Keyed by course id, which is how the catalogue cards look their own
    // count up. A count returned under any other key reads as zero on
    // every card rather than failing.
    expect($leaderboard->completedCountsByCourse($pilot)->all())->toBe([$live->course_id => 1]);
});

function challenge(bool $published = true): Challenge
{
    $course = Course::factory()->create(['is_published' => $published]);

    return Challenge::factory()->for($course)->create([
        'max_score' => 100,
        'is_published' => $published,
    ]);
}

function leaderboardPilot(string $name, Challenge $challenge, int $points): User
{
    $pilot = User::factory()->create(['name' => $name]);
    leaderboardProgress($pilot, $challenge, $points);

    return $pilot;
}

function leaderboardProgress(User $pilot, Challenge $challenge, int $points): void
{
    UserChallengeProgress::factory()->completed()->create([
        'user_id' => $pilot->id,
        'challenge_id' => $challenge->id,
        'best_score' => $points,
        'stars' => 3,
    ]);
}
