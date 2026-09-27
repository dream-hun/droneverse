<?php

declare(strict_types=1);

namespace App\Queries;

use App\Models\Challenge;
use App\Models\PilotMissionStats;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * What a pilot's flying adds up to, mission by mission.
 *
 * Every slice of {@see FlightLog} that is a *total* is computed here, and all
 * of them are read from {@see PilotMissionStats}, which is maintained as runs
 * land, rather than by aggregating the runs themselves. The one slice that
 * has to read the runs is {@see AttemptCurve}.
 *
 * Uncached: FlightLog decides what is cached and what a run retires, and this
 * is what it caches. Kept apart so that changing how a total is computed never
 * means reading past the invalidation policy to find it.
 *
 * Scoped throughout to content that is still playable, matching
 * {@see Leaderboard}: retiring a mission takes it out of the pilot's
 * analytics the same way it takes it off the board, so the two never
 * disagree about what counts.
 */
final readonly class FlightTotals
{
    /**
     * How many runs a mission needs before it can be called a weak spot.
     *
     * One bad flight is a bad flight. Below this the list would fill up with
     * missions the pilot opened once and left, which is not the same thing as
     * being stuck and is not worth a place on a page about where to practise.
     */
    private const int WEAK_SPOT_MIN_RUNS = 3;

    /**
     * A pilot's totals across everything they have flown.
     *
     * @return array{runs: int, missionsFlown: int, missionsCleared: int, clearRate: float, meanAttemptsToClear: float|null, flightSeconds: float, cleanRunRate: float, bestScore: int}
     */
    public function summaryFor(User $user): array
    {
        // One row per mission flown rather than one per run, so a
        // pilot's two hundredth attempt costs this aggregate exactly
        // what their second did.
        $row = fluent($this->playableFor($user)
            ->selectRaw(
                'coalesce(sum(pilot_mission_stats.runs), 0) as runs, '
                .'count(*) as missions_flown, '
                .'count(case when pilot_mission_stats.cleared = ? then 1 end) as missions_cleared, '
                .'coalesce(sum(pilot_mission_stats.clean_runs), 0) as clean_runs, '
                .'coalesce(sum(pilot_mission_stats.elapsed_seconds_total), 0) as flight_seconds, '
                .'coalesce(max(pilot_mission_stats.best_score), 0) as best_score, '
                .'avg(pilot_mission_stats.attempts_to_clear) as mean_attempts_to_clear',
                [true],
            )
            ->toBase()
            ->first());

        $runs = $row->integer('runs');
        $flown = $row->integer('missions_flown');
        $cleared = $row->integer('missions_cleared');

        return [
            'runs' => $runs,
            'missionsFlown' => $flown,
            'missionsCleared' => $cleared,
            'clearRate' => $flown === 0 ? 0.0 : round($cleared / $flown, 3),
            /*
             * `avg` skips the nulls, and null is exactly what a
             * mission the pilot has never cleared stores — so the
             * mean is taken over the missions that have a number,
             * and stays null when none of them do. That is a
             * different statement from zero.
             */
            'meanAttemptsToClear' => $row->get('mean_attempts_to_clear') === null
                ? null
                : round($row->float('mean_attempts_to_clear'), 1),
            'flightSeconds' => round($row->float('flight_seconds'), 1),
            'cleanRunRate' => $runs === 0
                ? 0.0
                : round($row->integer('clean_runs') / $runs, 3),
            'bestScore' => $row->integer('best_score'),
        ];
    }

    /**
     * Every mission this pilot has flown, most recently flown first.
     *
     * @return array<int, array{challengeTitle: string, challengeSlug: string, courseTitle: string, courseSlug: string, runs: int, cleared: bool}>
     */
    public function flownMissions(User $user): array
    {
        // Already one row per mission, so there is nothing left to
        // group: the rollup is the shape this slice wanted.
        $rows = $this->playableFor($user)
            ->selectRaw(
                'challenges.title as challenge_title, '
                .'challenges.slug as challenge_slug, '
                .'courses.title as course_title, '
                .'courses.slug as course_slug, '
                .'pilot_mission_stats.runs as runs, '
                .'pilot_mission_stats.cleared as cleared',
            )
            ->orderByDesc('pilot_mission_stats.last_run_id')
            ->toBase()
            ->get()
            ->all();

        return array_map($this->flownMission(...), $rows);
    }

    /**
     * The missions costing this pilot the most, worst first.
     *
     * Uncleared missions come first and are ordered by how many runs they
     * have swallowed, which is the closest thing to "where you are stuck"
     * that the data actually knows. Cleared missions follow, ordered the same
     * way, because a mission that took twenty attempts is still worth
     * revisiting even though it eventually fell.
     *
     * @return array<int, array{challengeTitle: string, challengeSlug: string, courseTitle: string, courseSlug: string, runs: int, bestScore: int, maxScore: int, cleared: bool, meanCollisions: float}>
     */
    public function weakSpots(User $user, int $limit): array
    {
        $rows = $this->playableFor($user)
            ->where('pilot_mission_stats.runs', '>=', self::WEAK_SPOT_MIN_RUNS)
            ->selectRaw(
                'challenges.title as challenge_title, '
                .'challenges.slug as challenge_slug, '
                .'challenges.max_score as max_score, '
                .'courses.title as course_title, '
                .'courses.slug as course_slug, '
                .'pilot_mission_stats.runs as runs, '
                .'pilot_mission_stats.best_score as best_score, '
                .'pilot_mission_stats.cleared as cleared, '
                // Summed rather than averaged in the rollup, so the
                // mean is exact however many runs it is taken over.
                .'pilot_mission_stats.collisions_total * 1.0 / pilot_mission_stats.runs as mean_collisions',
            )
            ->orderBy('cleared')
            ->orderByDesc('runs')
            ->orderBy('challenges.id')
            ->limit($limit)
            ->toBase()
            ->get()
            ->all();

        return array_map($this->weakSpot(...), $rows);
    }

    /**
     * Where a pilot's best on one mission sits against every pilot's.
     *
     * Null until the pilot has flown it — a percentile against a population
     * you are not in is not a number worth showing.
     *
     * The percentile counts pilots strictly below, so a pilot tied at the top
     * of a crowded mission does not read as having beaten themselves, and the
     * only pilot who has flown it reads as 0 rather than 100.
     *
     * @return array{percentile: int, pilots: int, yourBest: int, topBest: int}|null
     */
    public function cohortFor(User $user, Challenge $challenge): ?array
    {
        $yourBest = DB::table('pilot_mission_stats')
            ->where('user_id', $user->id)
            ->where('challenge_id', $challenge->id)
            ->value('best_score');

        if (! is_numeric($yourBest)) {
            return null;
        }

        $yourBest = (int) $yourBest;

        /*
         * The rollup is already one row per pilot carrying their
         * best, which is the population a percentile is taken over —
         * so the grouping subquery this used to need is gone, and
         * what is left reads a single index over one mission's rows.
         */
        $row = fluent(DB::table('pilot_mission_stats')
            ->where('challenge_id', $challenge->id)
            ->selectRaw(
                'count(*) as pilots, '
                .'count(case when best_score < ? then 1 end) as below, '
                .'coalesce(max(best_score), 0) as top_best',
                [$yourBest],
            )
            ->first());

        $pilots = $row->integer('pilots');

        return [
            'percentile' => $pilots === 0
                ? 0
                : (int) round($row->integer('below') / $pilots * 100),
            'pilots' => $pilots,
            'yourBest' => $yourBest,
            'topBest' => $row->integer('top_best'),
        ];
    }

    /**
     * @return array{challengeTitle: string, challengeSlug: string, courseTitle: string, courseSlug: string, runs: int, cleared: bool}
     */
    private function flownMission(stdClass $record): array
    {
        $row = fluent($record);

        return [
            'challengeTitle' => $row->string('challenge_title')->value(),
            'challengeSlug' => $row->string('challenge_slug')->value(),
            'courseTitle' => $row->string('course_title')->value(),
            'courseSlug' => $row->string('course_slug')->value(),
            'runs' => $row->integer('runs'),
            'cleared' => $row->boolean('cleared'),
        ];
    }

    /**
     * @return array{challengeTitle: string, challengeSlug: string, courseTitle: string, courseSlug: string, runs: int, bestScore: int, maxScore: int, cleared: bool, meanCollisions: float}
     */
    private function weakSpot(stdClass $record): array
    {
        $row = fluent($record);

        return [
            'challengeTitle' => $row->string('challenge_title')->value(),
            'challengeSlug' => $row->string('challenge_slug')->value(),
            'courseTitle' => $row->string('course_title')->value(),
            'courseSlug' => $row->string('course_slug')->value(),
            'runs' => $row->integer('runs'),
            'bestScore' => $row->integer('best_score'),
            'maxScore' => $row->integer('max_score'),
            'cleared' => $row->boolean('cleared'),
            'meanCollisions' => round($row->float('mean_collisions'), 2),
        ];
    }

    /**
     * Mission totals for content that is still playable, for one pilot.
     *
     * Every slice of a pilot's own totals reads through this, so "playable"
     * means exactly one thing — a published challenge inside a published
     * course — and retiring either end retires the totals from all of them at
     * once.
     *
     * That filter is the reason {@see PilotMissionStats} is keyed
     * by mission rather than rolled up any further: the publication check
     * stays a live join here, so pulling a mission takes it out of every
     * pilot's analytics immediately, with no rollup to rebuild first.
     *
     * Callers running an aggregate over it must finish with `toBase()`.
     * Eloquent would otherwise hydrate a PilotMissionStats out of a row that
     * is counts and averages rather than a mission's totals, and the model's
     * casts would be applied to columns that are not its own.
     *
     * @return EloquentBuilder<PilotMissionStats>
     */
    private function playableFor(User $user): EloquentBuilder
    {
        return PilotMissionStats::query()
            ->join('challenges', 'challenges.id', '=', 'pilot_mission_stats.challenge_id')
            ->join('courses', 'courses.id', '=', 'challenges.course_id')
            ->where('challenges.is_published', true)
            ->where('courses.is_published', true)
            ->where('pilot_mission_stats.user_id', $user->id);
    }
}
