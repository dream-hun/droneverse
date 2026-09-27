<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonInterface;
use Database\Factories\PilotCourseTotalsFactory;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * What one pilot's progress in one course adds up to.
 *
 * The leaderboard's read model, derived from {@see UserChallengeProgress} and
 * rebuilt from it by {@see \App\Actions\RollUpCourseTotals} whenever one of
 * those rows moves. It is never incremented: a run's effect on a standing is
 * a monotonic merge rather than a delta, so the row is recomputed from the
 * handful of progress rows behind it instead.
 *
 * A row exists only while the pilot has progress on a published mission in
 * the course — see the table's own migration for why the board would
 * otherwise gain pilots who have not flown anything that still counts.
 *
 * @property int $id
 * @property int $user_id
 * @property int $course_id
 * @property int $points
 * @property int $stars
 * @property int $completed
 * @property CarbonInterface|null $finished_at
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 */
#[Table(name: 'pilot_course_totals')]
final class PilotCourseTotals extends Model
{
    /** @use HasFactory<PilotCourseTotalsFactory> */
    use HasFactory;

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<Course, $this>
     */
    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    /**
     * Totals for content that is still playable today.
     *
     * Every read of the totals goes through this — the board's in
     * {@see \App\Queries\Leaderboard} and a pilot's own in
     * {@see \App\Queries\PilotProgress} — so "playable" means exactly one
     * thing everywhere. Half of it is enforced by the join below: retiring a
     * course takes its totals off the board at once, with no predicate left
     * behind to drift out of step. The other half, whether an individual
     * mission is published, is already folded into the stored totals by
     * {@see \App\Actions\RollUpCourseTotals}, which is why
     * {@see \App\Observers\ChallengeObserver} has to rebuild a course when
     * that changes.
     *
     * @param  Builder<PilotCourseTotals>  $query
     */
    #[Scope]
    protected function playable(Builder $query): void
    {
        $query->join('courses', 'courses.id', '=', 'pilot_course_totals.course_id')
            ->where('courses.is_published', true);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'points' => 'integer',
            'stars' => 'integer',
            'completed' => 'integer',
            'finished_at' => 'datetime',
        ];
    }
}
