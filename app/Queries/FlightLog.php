<?php

declare(strict_types=1);

namespace App\Queries;

use App\Models\Challenge;
use App\Models\ChallengeRun;
use App\Models\User;
use App\Queries\Support\SliceCache;

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
 * How each answer is computed is {@see FlightTotals}'s business, and
 * {@see AttemptCurve}'s for the one slice that reads the runs themselves.
 * This class is the cache in front of them: every slice is cached against
 * generation counters that {@see self::forget()} bumps when a run lands. Most
 * slices here are per-pilot and are read far less often than the
 * leaderboard, so the cache is mostly there to stop a page refresh re-running
 * four aggregates.
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

    private const int WEAK_SPOT_LIMIT = 5;

    public function __construct(
        private FlightTotals $totals,
        private AttemptCurve $curve,
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
            fn (): array => $this->curve->for($user, $challenge),
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
            fn (): array => $this->totals->summaryFor($user),
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
            fn (): array => $this->totals->flownMissions($user),
            shared: false,
        );
    }

    /**
     * The missions costing this pilot the most, worst first.
     *
     * @return array<int, array{challengeTitle: string, challengeSlug: string, courseTitle: string, courseSlug: string, runs: int, bestScore: int, maxScore: int, cleared: bool, meanCollisions: float}>
     */
    public function weakSpots(User $user, int $limit = self::WEAK_SPOT_LIMIT): array
    {
        return $this->cache->remember(
            sprintf('weak-spots:%d:%d', $user->id, $limit),
            [$this->pilotScope($user)],
            fn (): array => $this->totals->weakSpots($user, $limit),
            shared: false,
        );
    }

    /**
     * Where a pilot's best on one mission sits against every pilot's, or null
     * until they have flown it.
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
            fn (): ?array => $this->totals->cohortFor($user, $challenge),
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
}
