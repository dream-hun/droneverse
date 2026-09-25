<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonInterface;
use Database\Factories\QuizAttemptFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One graded submission, exactly as it was scored.
 *
 * The append-only counterpart to {@see UserQuizProgress}, standing to it as
 * {@see ChallengeRun} stands to {@see UserChallengeProgress}. Progress only
 * ever improves; an attempt is what actually happened on one submission,
 * including the ones that failed, and none of those survive the merge.
 *
 * @property int $id
 * @property string $uuid
 * @property int $user_id
 * @property int $quiz_id
 * @property int $score
 * @property int $correct_count
 * @property int $question_count
 * @property bool $passed
 * @property CarbonInterface|null $created_at
 */
final class QuizAttempt extends Model
{
    /** @use HasFactory<QuizAttemptFactory> */
    use HasFactory;

    use HasUuids;

    /**
     * Nothing revises a graded submission, so there is no `updated_at`.
     */
    public const UPDATED_AT = null;

    /**
     * Addressed publicly by uuid, never by id — same reasoning as
     * {@see ChallengeRun}: the auto-increment key stays because the foreign
     * keys and the pilot/quiz index are built on it, but an id in a URL is a
     * running count of every submission every pilot has ever made.
     */
    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    /**
     * Overridden because HasUuids assumes the uuid *is* the primary key.
     *
     * @return array<int, string>
     */
    public function uniqueIds(): array
    {
        return ['uuid'];
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
            'score' => 'integer',
            'correct_count' => 'integer',
            'question_count' => 'integer',
            'passed' => 'boolean',
            'created_at' => 'datetime',
        ];
    }
}
