<?php

namespace App\Models;

use App\Enums\ChallengeStatus;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Collection;

/**
 * @property int $id
 * @property int $user_id
 * @property int $challenge_id
 * @property ChallengeStatus $status
 * @property int $best_score
 * @property int $stars
 * @property string|null $last_code
 * @property int $attempts
 * @property CarbonInterface|null $completed_at
 */
#[Fillable(['user_id', 'challenge_id', 'status', 'best_score', 'stars', 'last_code', 'attempts', 'completed_at'])]
class UserChallengeProgress extends Model
{
    /** @use HasFactory<\Database\Factories\UserChallengeProgressFactory> */
    use HasFactory;

    /**
     * "progress" is uncountable, so the inflected table name happens to be
     * correct — declared explicitly so nobody has to reason about that.
     */
    protected $table = 'user_challenge_progress';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => ChallengeStatus::class,
            'best_score' => 'integer',
            'stars' => 'integer',
            'attempts' => 'integer',
            'completed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<Challenge, $this>
     */
    public function challenge(): BelongsTo
    {
        return $this->belongsTo(Challenge::class);
    }

    /**
     * Completed-challenge counts for the given user, keyed by course id.
     *
     * @return Collection<int, int>
     */
    public static function completedCountsByCourse(User $user): Collection
    {
        return static::query()
            ->join('challenges', 'challenges.id', '=', 'user_challenge_progress.challenge_id')
            ->where('user_challenge_progress.user_id', $user->id)
            ->where('user_challenge_progress.status', ChallengeStatus::Completed)
            ->selectRaw('challenges.course_id as course_id, count(*) as completed')
            ->groupBy('challenges.course_id')
            ->pluck('completed', 'course_id');
    }

    /**
     * Aggregate completion stats for the given user, in a single query.
     *
     * @return array{completed: int, stars: int}
     */
    public static function statsFor(User $user): array
    {
        $row = static::query()
            ->where('user_id', $user->id)
            ->selectRaw(
                'count(case when status = ? then 1 end) as completed, coalesce(sum(stars), 0) as stars',
                [ChallengeStatus::Completed->value],
            )
            ->first();

        return [
            'completed' => (int) ($row->completed ?? 0),
            'stars' => (int) ($row->stars ?? 0),
        ];
    }
}
