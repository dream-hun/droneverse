<?php

declare(strict_types=1);

namespace App\Queries;

use App\Models\Challenge;
use App\Models\ChallengeRun;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * One pilot's recent runs on one mission, as a curve.
 *
 * The one slice of {@see FlightLog} that still reads the runs themselves:
 * a curve is a list of individual flights, and there is nothing to roll it up
 * into. Every other slice is a total and reads {@see FlightTotals} instead.
 * The difference is not a constant factor: aggregating the raw table made the
 * cost of reading a pilot's analytics grow with how much they had flown, and
 * the curve is bounded by {@see self::CURVE_POINTS} where the aggregates
 * were bounded by nothing.
 */
final readonly class AttemptCurve
{
    /**
     * How many points an attempt curve carries at most.
     *
     * The curve is a shape, not a ledger. A pilot who has flown a mission two
     * hundred times is asking whether they are getting better, and the last
     * fifty runs answer that; the attempt numbers stay absolute so the window
     * never pretends to be the whole history.
     */
    private const int CURVE_POINTS = 50;

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
    public function for(User $user, Challenge $challenge): array
    {
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
    }
}
