<?php

declare(strict_types=1);

namespace App\Queries;

use App\Models\Challenge;
use App\Models\ChallengeRun;
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
 * Scoped throughout to content that is still playable, matching
 * {@see Leaderboard}: retiring a mission takes it out of the pilot's
 * analytics the same way it takes it off the board, so the two never
 * disagree about what counts.
 *
 * Cached against a generation counter that {@see self::forget()} bumps when a
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
     * Retire every cached slice.
     *
     * Called when a run lands. A run changes its pilot's summary, their curve
     * on that mission and their weak-spot ranking, and it moves the cohort
     * every other pilot on that mission is measured against — so rather than
     * work out which of those a given run touched, all of them are retired at
     * once.
     */
    public function forget(): void
    {
        $this->cache->flush();
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

                $earlier = DB::table('challenge_runs')
                    ->where('user_id', $user->id)
                    ->where('challenge_id', $challenge->id)
                    ->where('id', '<', $window->first()->id)
                    ->selectRaw('count(*) as runs, coalesce(max(score), 0) as best')
                    ->first();

                $attempt = (int) ($earlier->runs ?? 0);
                $best = (int) ($earlier->best ?? 0);

                return $window->map(function (ChallengeRun $run) use (&$attempt, &$best): array {
                    $attempt++;
                    $best = max($best, $run->score);

                    return [
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
                })->all();
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
            function () use ($user): array {
                $row = $this->playableFor($user)
                    ->selectRaw(
                        'count(*) as runs, '
                        .'count(distinct challenge_runs.challenge_id) as missions_flown, '
                        .'count(distinct case when challenge_runs.completed = ? then challenge_runs.challenge_id end) as missions_cleared, '
                        .'count(case when challenge_runs.collisions = 0 then 1 end) as clean_runs, '
                        .'coalesce(sum(challenge_runs.elapsed_seconds), 0) as flight_seconds, '
                        .'coalesce(max(challenge_runs.score), 0) as best_score',
                        [true],
                    )
                    ->toBase()
                    ->first();

                $runs = (int) ($row->runs ?? 0);
                $flown = (int) ($row->missions_flown ?? 0);
                $cleared = (int) ($row->missions_cleared ?? 0);

                return [
                    'runs' => $runs,
                    'missionsFlown' => $flown,
                    'missionsCleared' => $cleared,
                    'clearRate' => $flown === 0 ? 0.0 : round($cleared / $flown, 3),
                    'meanAttemptsToClear' => $this->meanAttemptsToClear($user),
                    'flightSeconds' => round((float) ($row->flight_seconds ?? 0), 1),
                    'cleanRunRate' => $runs === 0
                        ? 0.0
                        : round(((int) ($row->clean_runs ?? 0)) / $runs, 3),
                    'bestScore' => (int) ($row->best_score ?? 0),
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
            function () use ($user): array {
                $rows = $this->playableFor($user)
                    ->groupBy(
                        'challenges.id',
                        'challenges.title',
                        'challenges.slug',
                        'courses.title',
                        'courses.slug',
                    )
                    ->selectRaw(
                        'challenges.title as challenge_title, '
                        .'challenges.slug as challenge_slug, '
                        .'courses.title as course_title, '
                        .'courses.slug as course_slug, '
                        .'count(*) as runs, '
                        .'max(challenge_runs.id) as last_run_id, '
                        .'max(case when challenge_runs.completed = ? then 1 else 0 end) as cleared',
                        [true],
                    )
                    ->orderByDesc('last_run_id')
                    ->toBase()
                    ->get()
                    ->all();

                return array_map(fn (stdClass $row): array => [
                    'challengeTitle' => (string) $row->challenge_title,
                    'challengeSlug' => (string) $row->challenge_slug,
                    'courseTitle' => (string) $row->course_title,
                    'courseSlug' => (string) $row->course_slug,
                    'runs' => (int) $row->runs,
                    'cleared' => (bool) $row->cleared,
                ], $rows);
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
            function () use ($user, $limit): array {
                $rows = $this->playableFor($user)
                    ->groupBy(
                        'challenges.id',
                        'challenges.title',
                        'challenges.slug',
                        'challenges.max_score',
                        'courses.title',
                        'courses.slug',
                    )
                    ->havingRaw('count(*) >= ?', [self::WEAK_SPOT_MIN_RUNS])
                    ->selectRaw(
                        'challenges.title as challenge_title, '
                        .'challenges.slug as challenge_slug, '
                        .'challenges.max_score as max_score, '
                        .'courses.title as course_title, '
                        .'courses.slug as course_slug, '
                        .'count(*) as runs, '
                        .'coalesce(max(challenge_runs.score), 0) as best_score, '
                        .'coalesce(avg(challenge_runs.collisions), 0) as mean_collisions, '
                        .'max(case when challenge_runs.completed = ? then 1 else 0 end) as cleared',
                        [true],
                    )
                    ->orderBy('cleared')
                    ->orderByDesc('runs')
                    ->orderBy('challenges.id')
                    ->limit($limit)
                    ->toBase()
                    ->get()
                    ->all();

                return array_map(fn (stdClass $row): array => [
                    'challengeTitle' => (string) $row->challenge_title,
                    'challengeSlug' => (string) $row->challenge_slug,
                    'courseTitle' => (string) $row->course_title,
                    'courseSlug' => (string) $row->course_slug,
                    'runs' => (int) $row->runs,
                    'bestScore' => (int) $row->best_score,
                    'maxScore' => (int) $row->max_score,
                    'cleared' => (bool) $row->cleared,
                    'meanCollisions' => round((float) $row->mean_collisions, 2),
                ], $rows);
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
            function () use ($user, $challenge): ?array {
                $yourBest = DB::table('challenge_runs')
                    ->where('user_id', $user->id)
                    ->where('challenge_id', $challenge->id)
                    ->max('score');

                if ($yourBest === null) {
                    return null;
                }

                $yourBest = (int) $yourBest;

                // One row per pilot who has flown this mission, carrying
                // their best. `bests` is the population the percentile is
                // taken over, so a pilot with forty runs counts once.
                $bests = DB::table('challenge_runs')
                    ->where('challenge_id', $challenge->id)
                    ->groupBy('user_id')
                    ->selectRaw('max(score) as best');

                $row = DB::query()
                    ->fromSub($bests, 'bests')
                    ->selectRaw(
                        'count(*) as pilots, '
                        .'count(case when best < ? then 1 end) as below, '
                        .'coalesce(max(best), 0) as top_best',
                        [$yourBest],
                    )
                    ->first();

                $pilots = (int) ($row->pilots ?? 0);

                return [
                    'percentile' => $pilots === 0
                        ? 0
                        : (int) round(((int) ($row->below ?? 0)) / $pilots * 100),
                    'pilots' => $pilots,
                    'yourBest' => $yourBest,
                    'topBest' => (int) ($row->top_best ?? 0),
                ];
            },
            shared: false,
        );
    }

    /**
     * How many runs it takes this pilot to clear a mission, on average.
     *
     * Counted up to and including the run that cleared it — everything after
     * is a pilot chasing stars on a mission they have already beaten, and
     * folding that in would make a pilot who kept practising look slower than
     * one who moved on.
     *
     * Null when they have not cleared anything yet, which is a different
     * statement from zero.
     */
    private function meanAttemptsToClear(User $user): ?float
    {
        $firstClears = DB::table('challenge_runs')
            ->where('user_id', $user->id)
            ->where('completed', true)
            ->groupBy('challenge_id')
            ->selectRaw('challenge_id, min(id) as cleared_id');

        $perMission = DB::table('challenge_runs')
            ->joinSub($firstClears, 'first_clears', function ($join): void {
                $join->on('first_clears.challenge_id', '=', 'challenge_runs.challenge_id');
            })
            ->where('challenge_runs.user_id', $user->id)
            ->whereColumn('challenge_runs.id', '<=', 'first_clears.cleared_id')
            ->groupBy('challenge_runs.challenge_id')
            ->selectRaw('count(*) as attempts');

        $mean = DB::query()->fromSub($perMission, 'per_mission')->avg('attempts');

        return $mean === null ? null : round((float) $mean, 1);
    }

    /**
     * Runs against content that is still playable, for one pilot.
     *
     * Every aggregate here reads through this, so "playable" means exactly
     * one thing — a published challenge inside a published course — and
     * retiring either end retires the runs from all of them at once.
     *
     * Callers running an aggregate over it must finish with `toBase()`.
     * Eloquent would otherwise hydrate a ChallengeRun out of a row that is
     * counts and averages rather than a run, and the model's casts would be
     * applied to columns that are not its own.
     *
     * @return EloquentBuilder<ChallengeRun>
     */
    private function playableFor(User $user): EloquentBuilder
    {
        return ChallengeRun::query()
            ->join('challenges', 'challenges.id', '=', 'challenge_runs.challenge_id')
            ->join('courses', 'courses.id', '=', 'challenges.course_id')
            ->where('challenges.is_published', true)
            ->where('courses.is_published', true)
            ->where('challenge_runs.user_id', $user->id);
    }
}
