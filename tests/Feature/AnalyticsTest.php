<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\Plan;
use App\Models\Challenge;
use App\Models\ChallengeRun;
use App\Models\Course;
use App\Models\User;
use App\Queries\FlightLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Advanced analytics: who may read it, and whether it reads the runs right.
 */
final class AnalyticsTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_to_the_login_page(): void
    {
        $this->get(route('analytics'))->assertRedirect(route('login'));
    }

    public function test_a_starter_pilot_is_turned_away(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get(route('analytics'))->assertForbidden();
    }

    public function test_a_pro_pilot_may_read_it(): void
    {
        $user = User::factory()->onPlan(Plan::Pro)->create();

        $this->actingAs($user)
            ->get(route('analytics'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('analytics'));
    }

    public function test_a_pilot_who_has_flown_nothing_gets_an_empty_summary_rather_than_an_error(): void
    {
        $user = User::factory()->onPlan(Plan::Pro)->create();

        $this->actingAs($user)
            ->get(route('analytics'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('summary.runs', 0)
                ->where('summary.meanAttemptsToClear', null)
                ->where('missions', [])
                ->where('selected', null));
    }

    public function test_the_summary_counts_only_playable_content(): void
    {
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

        $this->assertSame(2, $summary['runs']);
        $this->assertSame(1, $summary['missionsFlown']);
    }

    public function test_the_curve_is_ordered_oldest_run_first_and_carries_a_running_best(): void
    {
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

        $this->assertSame([20, 60, 40, 90], array_column($curve, 'score'));
        $this->assertSame([1, 2, 3, 4], array_column($curve, 'attempt'));
        // The best never falls, and the third run does not undo the second.
        $this->assertSame([20, 60, 60, 90], array_column($curve, 'best'));
    }

    public function test_the_curve_is_empty_for_a_mission_the_pilot_has_not_flown(): void
    {
        $user = User::factory()->onPlan(Plan::Pro)->create();
        $challenge = Challenge::factory()->create();

        $this->assertSame([], resolve(FlightLog::class)->missionCurve($user, $challenge));
    }

    public function test_another_pilots_runs_never_appear_on_your_curve(): void
    {
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

        $this->assertSame([10], array_column($curve, 'score'));
    }

    public function test_attempts_to_clear_counts_only_the_runs_up_to_the_first_clear(): void
    {
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

        $this->assertSame(3.0, $summary['meanAttemptsToClear']);
        $this->assertSame(5, $summary['runs']);
    }

    public function test_weak_spots_list_the_uncleared_missions_first(): void
    {
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

        $this->assertSame(
            ['Stuck Here', 'Hard Won'],
            array_column($weakSpots, 'challengeTitle'),
            'a mission flown once is not a weak spot, and an uncleared one outranks a cleared one',
        );
        $this->assertFalse($weakSpots[0]['cleared']);
        $this->assertTrue($weakSpots[1]['cleared']);
    }

    public function test_the_cohort_measures_a_pilot_against_every_pilots_best(): void
    {
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

        $this->assertNotNull($cohort);
        $this->assertSame(50, $cohort['yourBest']);
        $this->assertSame(90, $cohort['topBest']);
        $this->assertSame(5, $cohort['pilots']);
        // Three of five pilots sit below 50, and the viewer is not one of them.
        $this->assertSame(60, $cohort['percentile']);
    }

    public function test_the_cohort_is_null_until_the_pilot_has_flown_the_mission(): void
    {
        $viewer = User::factory()->onPlan(Plan::Pro)->create();
        $challenge = Challenge::factory()->create();

        ChallengeRun::factory()->scoring(80)->create([
            'user_id' => User::factory()->create()->id,
            'challenge_id' => $challenge->id,
        ]);

        $this->assertNull(resolve(FlightLog::class)->cohortFor($viewer, $challenge));
    }

    public function test_an_unknown_mission_slug_falls_back_to_the_most_recently_flown(): void
    {
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
            ->assertInertia(fn ($page) => $page->where('selected.slug', $newer->slug));
    }

    public function test_a_mission_the_pilot_has_never_flown_cannot_be_selected(): void
    {
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
            ->assertInertia(fn ($page) => $page->where('selected.slug', $flown->slug));
    }

    public function test_the_play_page_withholds_the_flight_log_from_a_starter_pilot(): void
    {
        $user = User::factory()->create();
        $course = Course::factory()->create();
        $challenge = Challenge::factory()->for($course)->create();

        $this->actingAs($user)
            ->get(route('challenges.show', [$course, $challenge]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('flightLog', null));
    }

    public function test_the_play_page_offers_the_flight_log_to_a_pro_pilot(): void
    {
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
            ->assertInertia(fn ($page) => $page
                // Deferred, so the cockpit paints before the two aggregates
                // behind the panel have run.
                ->missing('flightLog')
                ->loadDeferredProps(fn ($reload) => $reload
                    ->has('flightLog.curve', 1)
                    ->where('flightLog.curve.0.score', 45)
                    ->where('flightLog.cohort.yourBest', 45)));
    }
}
