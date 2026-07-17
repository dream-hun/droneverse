<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ChallengeStatus;
use Carbon\CarbonInterface;
use Database\Factories\UserChallengeProgressFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
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
#[Table(name: 'user_challenge_progress')]
final class UserChallengeProgress extends Model
{
    /** @use HasFactory<UserChallengeProgressFactory> */
    use HasFactory;

    /**
     * Completed-challenge counts for the given user, keyed by course id.
     *
     * Only currently playable content counts (published challenge in a
     * published course), so a count can never exceed the published-challenge
     * total displayed beside it.
     *
     * @return Collection<int, int>
     */
    public static function completedCountsByCourse(User $user): Collection
    {
        return self::query()
            ->join('challenges', 'challenges.id', '=', 'user_challenge_progress.challenge_id')
            ->join('courses', 'courses.id', '=', 'challenges.course_id')
            ->where('user_challenge_progress.user_id', $user->id)
            ->where('user_challenge_progress.status', ChallengeStatus::Completed)
            ->where('challenges.is_published', true)
            ->where('courses.is_published', true)
            ->selectRaw('challenges.course_id as course_id, count(*) as completed')
            ->groupBy('challenges.course_id')
            ->pluck('completed', 'course_id');
    }

    /**
     * Aggregate completion stats for the given user, in a single query.
     *
     * Scoped to currently playable content, matching the per-course counts
     * shown alongside these totals.
     *
     * @return array{completed: int, stars: int}
     */
    public static function statsFor(User $user): array
    {
        $row = self::query()
            ->join('challenges', 'challenges.id', '=', 'user_challenge_progress.challenge_id')
            ->join('courses', 'courses.id', '=', 'challenges.course_id')
            ->where('user_challenge_progress.user_id', $user->id)
            ->where('challenges.is_published', true)
            ->where('courses.is_published', true)
            ->selectRaw(
                'count(case when user_challenge_progress.status = ? then 1 end) as completed, coalesce(sum(user_challenge_progress.stars), 0) as stars',
                [ChallengeStatus::Completed->value],
            )
            ->first();

        return [
            'completed' => (int) ($row->completed ?? 0),
            'stars' => (int) ($row->stars ?? 0),
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
}
