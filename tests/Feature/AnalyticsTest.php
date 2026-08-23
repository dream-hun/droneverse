<?php

declare(strict_types=1);

use App\Enums\Plan;
use App\Models\Challenge;
use App\Models\ChallengeRun;
use App\Models\Course;
use App\Models\User;
use App\Queries\FlightLog;

/*
 * Advanced analytics: who may read it, and whether it reads the runs right.
 */

test('guests are redirected to the login page', function (): void {
    $this->get(route('analytics'))->assertRedirect(route('login'));
});

test('a starter pilot is turned away', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)->get(route('analytics'))->assertForbidden();
});

test('a pro pilot may read it', function (): void {
    $user = User::factory()->onPlan(Plan::Pro)->create();

    $this->actingAs($user)
        ->get(route('analytics'))
        ->assertOk()
        ->assertInertia(fn($page) => $page->component('analytics'));
});

test('a pilot who has flown nothing gets an empty summary rather than an error', function (): void {
    $user = User::factory()->onPlan(Plan::Pro)->create();

    $this->actingAs($user)
        ->get(route('analytics'))
        ->assertOk()
        ->assertInertia(fn($page) => $page
            ->where('summary.runs', 0)
            ->where('summary.meanAttemptsToClear', null)
            ->where('missions', [])
            ->where('selected', null));
});

test('the summary counts only playable content', function (): void {
    $user = User::factory()->onPlan(Plan::Pro)->create();
    $course = Course::factory()->create();
    $live = Challenge::factory()->for($course)->create();
    $retired = Challenge::factory()->for($course)->unpublished()->create();

    ChallengeRun::factory()->count(2)->create([
        'user_id' => $user->id,
        'challenge_id' => $live->id,
    ]);
    ChallengeRun::factory()->count(5)->create([
        'user_id' => $user->id,
        'challenge_id' => $retired->id,
    ]);

    $summary = resolve(FlightLog::class)->summaryFor($user);

    expect($summary['runs'])->toBe(2)
        ->and($summary['missionsFlown'])->toBe(1);
});

test('the curve is ordered oldest run first and carries a running best', function (): void {
    $user = User::factory()->onPlan(Plan::Pro)->create();
    $course = Course::factory()->create();
    $challenge = Challenge::factory()->for($course)->create(['max_score' => 100]);

    foreach ([20, 60, 40, 90] as $score) {
        ChallengeRun::factory()->scoring($score)->create([
            'user_id' => $user->id,
            'challenge_id' => $challenge->id,
        ]);
    }

    $curve = resolve(FlightLog::class)->missionCurve($user, $challenge);

    expect(array_column($curve, 'score'))->toBe([20, 60, 40, 90])
        ->and(array_column($curve, 'attempt'))->toBe([1, 2, 3, 4])
        ->and(array_column($curve, 'best'))->toBe([20, 60, 60, 90]);
    // The best never falls, and the third run does not undo the second.
});

test('the curve is empty for a mission the pilot has not flown', function (): void {
    $user = User::factory()->onPlan(Plan::Pro)->create();
    $challenge = Challenge::factory()->create();

    expect(resolve(FlightLog::class)->missionCurve($user, $challenge))->toBe([]);
});

test('another pilots runs never appear on your curve', function (): void {
    $course = Course::factory()->create();
    $challenge = Challenge::factory()->for($course)->create();

    $user = User::factory()->onPlan(Plan::Pro)->create();
    $stranger = User::factory()->create();

    ChallengeRun::factory()->scoring(10)->create([
        'user_id' => $user->id,
        'challenge_id' => $challenge->id,
    ]);
    ChallengeRun::factory()->scoring(95)->create([
        'user_id' => $stranger->id,
        'challenge_id' => $challenge->id,
    ]);

    $curve = resolve(FlightLog::class)->missionCurve($user, $challenge);

    expect(array_column($curve, 'score'))->toBe([10]);
});

test('attempts to clear counts only the runs up to the first clear', function (): void {
    $user = User::factory()->onPlan(Plan::Pro)->create();
    $course = Course::factory()->create();
    $challenge = Challenge::factory()->for($course)->create();

    // Two misses, the clear, then two more runs chasing stars.
    ChallengeRun::factory()->count(2)->create([
        'user_id' => $user->id,
        'challenge_id' => $challenge->id,
    ]);
    ChallengeRun::factory()->completed()->create([
        'user_id' => $user->id,
        'challenge_id' => $challenge->id,
    ]);
    ChallengeRun::factory()->count(2)->create([
        'user_id' => $user->id,
        'challenge_id' => $challenge->id,
    ]);

    $summary = resolve(FlightLog::class)->summaryFor($user);

    expect($summary['meanAttemptsToClear'])->toBe(3.0)
        ->and($summary['runs'])->toBe(5);
});

test('weak spots list the uncleared missions first', function (): void {
    $user = User::factory()->onPlan(Plan::Pro)->create();
    $course = Course::factory()->create();

    $stuck = Challenge::factory()->for($course)->create(['title' => 'Stuck Here']);
    $hardWon = Challenge::factory()->for($course)->create(['title' => 'Hard Won']);
    $flownOnce = Challenge::factory()->for($course)->create(['title' => 'Barely Touched']);

    ChallengeRun::factory()->count(4)->create([
        'user_id' => $user->id,
        'challenge_id' => $stuck->id,
    ]);

    // More runs than the stuck mission, but it eventually fell.
    ChallengeRun::factory()->count(6)->create([
        'user_id' => $user->id,
        'challenge_id' => $hardWon->id,
    ]);
    ChallengeRun::factory()->completed()->create([
        'user_id' => $user->id,
        'challenge_id' => $hardWon->id,
    ]);

    ChallengeRun::factory()->create([
        'user_id' => $user->id,
        'challenge_id' => $flownOnce->id,
    ]);

    $weakSpots = resolve(FlightLog::class)->weakSpots($user);

    expect(array_column($weakSpots, 'challengeTitle'))
        ->toBe(
            ['Stuck Here', 'Hard Won'],
            'a mission flown once is not a weak spot, and an uncleared one outranks a cleared one',
        )
        ->and($weakSpots[0]['cleared'])->toBeFalse()
        ->and($weakSpots[1]['cleared'])->toBeTrue();
});

test('the cohort measures a pilot against every pilots best', function (): void {
    $course = Course::factory()->create();
    $challenge = Challenge::factory()->for($course)->create();

    $viewer = User::factory()->onPlan(Plan::Pro)->create();

    // Three pilots below, one above.
    foreach ([10, 20, 30, 90] as $score) {
        ChallengeRun::factory()->scoring($score)->create([
            'user_id' => User::factory()->create()->id,
            'challenge_id' => $challenge->id,
        ]);
    }

    // Two runs for the viewer; only their best counts.
    ChallengeRun::factory()->scoring(5)->create([
        'user_id' => $viewer->id,
        'challenge_id' => $challenge->id,
    ]);
    ChallengeRun::factory()->scoring(50)->create([
        'user_id' => $viewer->id,
        'challenge_id' => $challenge->id,
    ]);

    $cohort = resolve(FlightLog::class)->cohortFor($viewer, $challenge);

    expect($cohort)->not->toBeNull()
        ->and($cohort['yourBest'])->toBe(50)
        ->and($cohort['topBest'])->toBe(90)
        ->and($cohort['pilots'])->toBe(5)
        ->and($cohort['percentile'])->toBe(60);
    // Three of five pilots sit below 50, and the viewer is not one of them.
});

test('the cohort is null until the pilot has flown the mission', function (): void {
    $viewer = User::factory()->onPlan(Plan::Pro)->create();
    $challenge = Challenge::factory()->create();

    ChallengeRun::factory()->scoring(80)->create([
        'user_id' => User::factory()->create()->id,
        'challenge_id' => $challenge->id,
    ]);

    expect(resolve(FlightLog::class)->cohortFor($viewer, $challenge))->toBeNull();
});

test('an unknown mission slug falls back to the most recently flown', function (): void {
    $user = User::factory()->onPlan(Plan::Pro)->create();
    $course = Course::factory()->create();
    $older = Challenge::factory()->for($course)->create();
    $newer = Challenge::factory()->for($course)->create();

    ChallengeRun::factory()->create([
        'user_id' => $user->id,
        'challenge_id' => $older->id,
    ]);
    ChallengeRun::factory()->create([
        'user_id' => $user->id,
        'challenge_id' => $newer->id,
    ]);

    $this->actingAs($user)
        ->get(route('analytics', ['mission' => 'no-such-mission']))
        ->assertOk()
        ->assertInertia(fn($page) => $page->where('selected.slug', $newer->slug));
});

test('a mission the pilot has never flown cannot be selected', function (): void {
    $user = User::factory()->onPlan(Plan::Pro)->create();
    $course = Course::factory()->create();
    $flown = Challenge::factory()->for($course)->create();
    $unflown = Challenge::factory()->for($course)->create();

    ChallengeRun::factory()->create([
        'user_id' => $user->id,
        'challenge_id' => $flown->id,
    ]);

    $this->actingAs($user)
        ->get(route('analytics', ['mission' => $unflown->slug]))
        ->assertOk()
        ->assertInertia(fn($page) => $page->where('selected.slug', $flown->slug));
});

test('the play page withholds the flight log from a starter pilot', function (): void {
    $user = User::factory()->create();
    $course = Course::factory()->create();
    $challenge = Challenge::factory()->for($course)->create();

    $this->actingAs($user)
        ->get(route('challenges.show', [$course, $challenge]))
        ->assertOk()
        ->assertInertia(fn($page) => $page->where('flightLog', null));
});

test('the play page offers the flight log to a pro pilot', function (): void {
    $user = User::factory()->onPlan(Plan::Pro)->create();
    $course = Course::factory()->create();
    $challenge = Challenge::factory()->for($course)->create();

    ChallengeRun::factory()->scoring(45)->create([
        'user_id' => $user->id,
        'challenge_id' => $challenge->id,
    ]);

    $this->actingAs($user)
        ->get(route('challenges.show', [$course, $challenge]))
        ->assertOk()
        ->assertInertia(fn($page) => $page
            ->missing('flightLog')
            ->loadDeferredProps(fn($reload) => $reload
                ->has('flightLog.curve', 1)
                ->where('flightLog.curve.0.score', 45)
                ->where('flightLog.cohort.yourBest', 45)));
});
