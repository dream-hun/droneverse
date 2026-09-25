<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\QuizStatus;
use Carbon\CarbonInterface;
use Database\Factories\UserQuizProgressFactory;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A pilot's standing on one quiz.
 *
 * The counterpart of {@see UserChallengeProgress}, and monotonic in the same
 * way: `best_score` only rises and `passed_at` is set once. Retakes are
 * unlimited, so that guarantee is what lets a pilot reopen a quiz to review
 * the questions without risking the pass they already hold.
 *
 * @property int $id
 * @property int $user_id
 * @property int $quiz_id
 * @property int $best_score
 * @property int $attempts
 * @property CarbonInterface|null $passed_at
 */
#[Table(name: 'user_quiz_progress')]
final class UserQuizProgress extends Model
{
    /** @use HasFactory<UserQuizProgressFactory> */
    use HasFactory;

    /**
     * This row read as one of three states.
     *
     * Derived rather than stored, because it is entirely a function of two
     * columns that are already here and a stored copy is one more thing that
     * can disagree with them. `passed_at` is the authority on a pass — not
     * `best_score` against the current pass mark, which would silently revoke
     * a pass if an author later raised the bar.
     */
    public function status(): QuizStatus
    {
        if ($this->passed_at !== null) {
            return QuizStatus::Passed;
        }

        return $this->attempts > 0 ? QuizStatus::Attempted : QuizStatus::NotStarted;
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<Quiz, $this>
     */
    public function quiz(): BelongsTo
    {
        return $this->belongsTo(Quiz::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'best_score' => 'integer',
            'attempts' => 'integer',
            'passed_at' => 'datetime',
        ];
    }
}
