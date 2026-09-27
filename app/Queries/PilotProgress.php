<?php

declare(strict_types=1);

namespace App\Queries;

use App\Models\PilotCourseTotals;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * How far one pilot has got, course by course.
 *
 * The totals a pilot sees about themselves on the dashboard and the catalogue,
 * read off the same {@see PilotCourseTotals} rows the leaderboard ranks — but
 * not ranked, not compared with anyone, and so not the leaderboard's to serve.
 *
 * Only currently playable content counts (a published challenge in a published
 * course), so a count can never exceed the published-challenge total displayed
 * beside it, and never disagrees with the board about what counts.
 */
final readonly class PilotProgress
{
    /**
     * Completed-challenge counts for the given user, keyed by course id.
     *
     * @return Collection<int, int>
     */
    public function completedCountsByCourse(User $user): Collection
    {
        // Already one row per course, so this is a lookup rather than an
        // aggregate: the count the rollup keeps is the count being asked for.
        return $this->playableFor($user)
            ->get(['pilot_course_totals.course_id', 'pilot_course_totals.completed'])
            ->mapWithKeys(fn (PilotCourseTotals $totals): array => [$totals->course_id => $totals->completed]);
    }

    /**
     * Aggregate completion stats for the given user, in a single query.
     *
     * @return array{completed: int, stars: int}
     */
    public function statsFor(User $user): array
    {
        $row = fluent($this->playableFor($user)
            ->selectRaw(
                'coalesce(sum(pilot_course_totals.completed), 0) as completed, '
                .'coalesce(sum(pilot_course_totals.stars), 0) as stars',
            )
            ->toBase()
            ->first());

        return [
            'completed' => $row->integer('completed'),
            'stars' => $row->integer('stars'),
        ];
    }

    /**
     * @return Builder<PilotCourseTotals>
     */
    private function playableFor(User $user): Builder
    {
        return PilotCourseTotals::query()
            ->playable()
            ->where('pilot_course_totals.user_id', $user->id);
    }
}
