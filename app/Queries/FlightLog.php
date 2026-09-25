<?php

declare(strict_types=1);

namespace App\Queries;

use App\Models\Challenge;
use App\Models\ChallengeRun;
use App\Models\PilotMissionStats;
use App\Models\User;
use App\Queries\Support\SliceCache;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * What a pilot's runs add up to.
 *
 * The read model behind advanced analytics, over {@see ChallengeRun} rather
 * than {@see \App\Models\UserChallengeProgress}. That is the whole reason the
 * run table exists: progress keeps a pilot's best and nothing else, so it can
 * say a mission took eleven attempts but never what changed between them.
 * Every question here — did the score climb or plateau, are the collisions
 * coming down, which mission is actually costing the most attempts — is a
 * question about the runs.
 *
 * Only one of them still reads the runs themselves. {@see self::missionCurve()}
 * is a list of individual flights and there is nothing to roll a curve up
 * into; every other slice is a *total*, and totals are read from
 * {@see PilotMissionStats}, which is maintained as runs land. The difference
 * is not a constant factor: aggregating the raw table made the cost of
 * reading a pilot's analytics grow with how much they had flown, and the
 * curve is bounded by {@see self::CURVE_POINTS} where the aggregates were
 * bounded by nothing.
 *
 * Scoped throughout to content that is still playable, matching
 * {@see Leaderboard}: retiring a mission takes it out of the pilot's
 * analytics the same way it takes it off the board, so the two never
 * disagree about what counts.
 *
 * Cached against generation counters that {@see self::forget()} bumps when a
 * run lands. Most slices here are per-pilot and are read far less often than
 * the leaderboard, so the cache is mostly there to stop a page refresh
 * re-running four aggregates.
 */
final readonly class FlightLog
{
    /**
     * How long a cached slice may live unattended.
     *
     * Recording a run retires them explicitly, so this is only a backstop for
     * the things that move a slice without going through an attempt — a
     * course being published or pulled.
     */
    private const int LOG_TTL_SECONDS = 300;

    /**
     * How many points an attempt curve carries at most.
     *
     * The curve is a shape, not a ledger. A pilot who has flown a mission two
     * hundred times is asking whether they are getting better, and the last
     * fifty runs answer that; the attempt numbers stay absolute so the window
     * never pretends to be the whole history.
     */
    private const int CURVE_POINTS = 50;

    private const int WEAK_SPOT_LIMIT = 5;

    /**
     * How many runs a mission needs before it can be called a weak spot.
     *
     * One bad flight is a bad flight. Below this the list would fill up with
     * missions the pilot opened once and left, which is not the same thing as
     * being stuck and is not worth a place on a page about where to practise.
     */
    private const int WEAK_SPOT_MIN_RUNS = 3;

    public function __construct(
        private SliceCache $cache = new SliceCache('flight-log', self::LOG_TTL_SECONDS),
    ) {}

    /**
     * Retire the cached slices this run could have moved, and no others.
     *
     * A run changes exactly three populations: everything drawn from this
     * pilot's runs (their summary, the missions they have flown, their weak
     * spots), their own curve on this mission, and the cohort every pilot on
     * this mission is measured against. Three counters, one write each.
     *
     * What this replaces is the reason it exists. There was a single counter
     * per read model, so one pilot landing a run retired every cached slice
     * belonging to every pilot — on a page where almost every slice is
     * per-pilot and could not possibly have been affected. The busier the
     * simulator got, the closer the analytics cache came to never being read
     * at all.
     */
    public function forget(User $user, Challenge $challenge): void
    {
        $this->cache->flush(
            $this->pilotScope($user),
            $this->flightScope($user, $challenge),
            $this->missionScope($challenge),
        );
    }

    /**
     * How one pilot's scores moved on one mission, oldest run first.
     *
     * `best` is the pilot's best score as at that run, so the curve carries
     * its own ceiling and a plateau is visible without a second series. It is
     * seeded from the runs before the window rather than from the window
     * alone: a pilot whose best run was their sixtieth-from-last would
     * otherwise watch their best appear to reset.
     *
     * @return array<int, array{attempt: int, score: int, best: int, stars: int, completed: bool, collisions: int, elapsedSeconds: float, objectivesHit: int, objectivesTotal: int, flownAt: string}>
     */
    public function missionCurve(User $user, Challenge $challenge): array
    {
        return $this->cache->remember(
            sprintf('curve:%d:%d', $challenge->id, $user->id),
            // Only this pilot's runs on this mission are in it, so a run they
            // fly elsewhere leaves the curve alone — and another pilot's run
            // here never touched it in the first place.
            [$this->flightScope($user, $challenge)],
            function () use ($user, $challenge): array {
                // Newest first, then reversed: the index is ordered by id, so
                // taking the tail of a long history costs the same as taking
                // the head of a short one.
                $window = ChallengeRun::query()
                    ->where('user_id', $user->id)
                    ->where('challenge_id', $challenge->id)
                    ->orderByDesc('id')
                    ->limit(self::CURVE_POINTS)
                    ->get()
                    ->reverse()
                    ->values();

                if ($window->isEmpty()) {
                    return [];
                }

                $earlier = fluent(DB::table('challenge_runs')
                    ->where('user_id', $user->id)
                    ->where('challenge_id', $challenge->id)
                    ->where('id', '<', $window->first()->id)
                    ->selectRaw('count(*) as runs, coalesce(max(score), 0) as best')
                    ->first());

                $attempt = $earlier->integer('runs');
                $best = $earlier->integer('best');

                $curve = [];

                foreach ($window as $run) {
                    $attempt++;
                    $best = max($best, $run->score);

                    $curve[] = [
                        'attempt' => $attempt,
                        'score' => $run->score,
                        'best' => $best,
                        'stars' => $run->stars,
                        'completed' => $run->completed,
                        'collisions' => $run->collisions,
                        'elapsedSeconds' => round($run->elapsed_seconds, 2),
                        'objectivesHit' => $run->objectives_hit,
                        'objectivesTotal' => $run->objectives_total,
                        'flownAt' => $run->created_at?->toIso8601String() ?? '',
                    ];
                }

                return $curve;
            },
            // Keyed to one pilot on one mission, so the only requests that
            // can collide on it are that pilot's own.
            shared: false,
        );
    }

    /**
     * A pilot's totals across everything they have flown.
     *
     * @return array{runs: int, missionsFlown: int, missionsCleared: int, clearRate: float, meanAttemptsToClear: float|null, flightSeconds: float, cleanRunRate: float, bestScore: int}
     */
    public function summaryFor(User $user): array
    {
        return $this->cache->remember(
            sprintf('summary:%d', $user->id),
            [$this->pilotScope($user)],
            function () use ($user): array {
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
            },
            shared: false,
        );
    }

    /**
     * Every mission this pilot has flown, most recently flown first.
     *
     * The population the analytics page lets a pilot choose a curve from, so
     * it lists what they have actually flown rather than the whole catalogue
     * — a mission with no runs has no curve to draw.
     *
     * @return array<int, array{challengeTitle: string, challengeSlug: string, courseTitle: string, courseSlug: string, runs: int, cleared: bool}>
     */
    public function flownMissions(User $user): array
    {
        return $this->cache->remember(
            sprintf('missions:%d', $user->id),
            [$this->pilotScope($user)],
            function () use ($user): array {
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

                return array_map(function (stdClass $record): array {
                    $row = fluent($record);

                    return [
                        'challengeTitle' => $row->string('challenge_title')->value(),
                        'challengeSlug' => $row->string('challenge_slug')->value(),
                        'courseTitle' => $row->string('course_title')->value(),
                        'courseSlug' => $row->string('course_slug')->value(),
                        'runs' => $row->integer('runs'),
                        'cleared' => $row->boolean('cleared'),
                    ];
                }, $rows);
            },
            shared: false,
        );
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
    public function weakSpots(User $user, int $limit = self::WEAK_SPOT_LIMIT): array
    {
        return $this->cache->remember(
            sprintf('weak-spots:%d:%d', $user->id, $limit),
            [$this->pilotScope($user)],
            function () use ($user, $limit): array {
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

                return array_map(function (stdClass $record): array {
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
                }, $rows);
            },
            shared: false,
        );
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
        return $this->cache->remember(
            sprintf('cohort:%d:%d', $challenge->id, $user->id),
            // A percentile is a statement about the whole population on this
            // mission, so anyone's run here moves it — including this
            // pilot's, which bumps the mission scope along with their own.
            [$this->missionScope($challenge)],
            function () use ($user, $challenge): ?array {
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
            },
            shared: false,
        );
    }

    /**
     * Everything drawn from one pilot's runs, wherever they were flown.
     */
    private function pilotScope(User $user): string
    {
        return sprintf('pilot:%d', $user->id);
    }

    /**
     * One pilot's runs on one mission.
     *
     * Narrower than {@see self::pilotScope()} on purpose: a curve is the one
     * slice that cares about a single pairing, and giving it a scope of its
     * own is what lets a pilot's flight on another mission leave it standing.
     */
    private function flightScope(User $user, Challenge $challenge): string
    {
        return sprintf('flight:%d:%d', $user->id, $challenge->id);
    }

    /**
     * Every pilot's runs on one mission — the cohort a percentile is taken
     * over.
     */
    private function missionScope(Challenge $challenge): string
    {
        return sprintf('mission:%d', $challenge->id);
    }

    /**
     * Mission totals for content that is still playable, for one pilot.
     *
     * Every slice but the curve reads through this, so "playable" means
     * exactly one thing — a published challenge inside a published course —
     * and retiring either end retires the totals from all of them at once.
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
