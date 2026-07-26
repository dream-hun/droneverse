<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ChallengeStatus;
use Database\Factories\ChallengeFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $course_id
 * @property string $title
 * @property string $slug
 * @property string $briefing
 * @property int $order
 * @property string $difficulty
 * @property string $starter_code
 * @property string|null $solution_code
 * @property array<string, mixed> $environment
 * @property array<string, mixed> $success_criteria
 * @property int $max_score
 * @property bool $is_published
 */
#[Fillable(['course_id', 'title', 'slug', 'briefing', 'order', 'difficulty', 'starter_code', 'solution_code', 'environment', 'success_criteria', 'max_score', 'is_published'])]
#[Hidden(['solution_code'])]
final class Challenge extends Model
{
    /** @use HasFactory<ChallengeFactory> */
    use HasFactory;

    /**
     * Runs a pilot may make before the reference solution unlocks.
     *
     * Completing the mission unlocks it immediately; this is the escape
     * hatch for pilots who are stuck rather than done.
     */
    public const int ATTEMPTS_BEFORE_SOLUTION = 3;

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    /**
     * Whether this mission can be flown as part of the given course.
     *
     * Both ends have to be live and the challenge has to actually belong to
     * the course in the URL, or the pair is indistinguishable from content
     * that does not exist. Every route carrying a course/challenge pair
     * asks this one question, so the answer cannot drift between them.
     */
    public function isPlayableIn(Course $course): bool
    {
        return $this->is_published
            && $course->is_published
            && $this->course_id === $course->id;
    }

    /**
     * Whether the reference solution may be shown for this progress record.
     *
     * A mission with no authored solution never unlocks, so the panel can
     * key off this single answer.
     */
    public function solutionUnlockedBy(?UserChallengeProgress $progress): bool
    {
        if ($this->solution_code === null) {
            return false;
        }

        if (! $progress instanceof UserChallengeProgress) {
            return false;
        }

        return $progress->status === ChallengeStatus::Completed
            || $progress->attempts >= self::ATTEMPTS_BEFORE_SOLUTION;
    }

    /**
     * @return BelongsTo<Course, $this>
     */
    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    /**
     * @return HasMany<UserChallengeProgress, $this>
     */
    public function progress(): HasMany
    {
        return $this->hasMany(UserChallengeProgress::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'environment' => 'array',
            'success_criteria' => 'array',
            'is_published' => 'boolean',
            'order' => 'integer',
            'max_score' => 'integer',
        ];
    }

    /**
     * @param  Builder<Challenge>  $query
     */
    #[Scope]
    protected function published(Builder $query): void
    {
        $query->where('is_published', true);
    }
}
