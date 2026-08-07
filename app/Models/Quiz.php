<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Plan;
use Database\Factories\QuizFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A knowledge check attached to a course.
 *
 * The catalog's second half. A {@see Challenge} asks whether a pilot can make
 * the drone do something; a quiz asks whether they understood why. Both are
 * authored content hanging off a {@see Course}, both are gated the same way,
 * and neither knows anything about billing beyond the plan name it stores.
 *
 * @property int $id
 * @property int $course_id
 * @property string $title
 * @property string $slug
 * @property string $description
 * @property int $order
 * @property string|null $required_plan
 * @property int $pass_percentage
 * @property bool $is_published
 * @property-read int|null $questions_count
 */
#[Fillable(['course_id', 'title', 'slug', 'description', 'order', 'required_plan', 'pass_percentage', 'is_published'])]
final class Quiz extends Model
{
    /** @use HasFactory<QuizFactory> */
    use HasFactory;

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    /**
     * Whether this quiz can be taken as part of the given course.
     *
     * The exact counterpart of {@see Challenge::isPlayableIn()}, and it has to
     * stay that way: both ends have to be live and the quiz has to actually
     * belong to the course in the URL, or the pair is indistinguishable from
     * content that does not exist. Every route carrying a course/quiz pair
     * asks this one question.
     */
    public function isAvailableIn(Course $course): bool
    {
        return $this->is_published
            && $course->is_published
            && $this->course_id === $course->id;
    }

    /**
     * The plan a pilot needs to take this quiz as part of the given course.
     *
     * Mirrors {@see Challenge::requiredPlanIn()} exactly, including the reason
     * the course is passed in rather than read off the relation: every caller
     * already has it from the route, and reaching for `$this->course` here
     * would fire a query per row on a course page.
     */
    public function requiredPlanIn(Course $course): Plan
    {
        return Plan::tryFrom($this->required_plan ?? '') ?? $course->requiredPlan();
    }

    /**
     * Whether this viewer's plan reaches the quiz. Guests get Starter.
     */
    public function isUnlockedFor(?User $user, Course $course): bool
    {
        $plan = $user?->plan() ?? Plan::Starter;

        return $plan->covers($this->requiredPlanIn($course));
    }

    /**
     * Whether a percentage score clears this quiz's bar.
     *
     * The single place the comparison is made. The pass mark is a percentage
     * rather than a count so that adding a question to a published quiz does
     * not move the bar underneath the pilots who already passed it, and
     * keeping the comparison here means the grader, the progress merge and
     * any future report all round the same way.
     */
    public function isPassedBy(int $score): bool
    {
        return $score >= $this->pass_percentage;
    }

    /**
     * @return BelongsTo<Course, $this>
     */
    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    /**
     * @return HasMany<QuizQuestion, $this>
     */
    public function questions(): HasMany
    {
        return $this->hasMany(QuizQuestion::class)->orderBy('order');
    }

    /**
     * @return HasMany<UserQuizProgress, $this>
     */
    public function progress(): HasMany
    {
        return $this->hasMany(UserQuizProgress::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_published' => 'boolean',
            'order' => 'integer',
            'pass_percentage' => 'integer',
        ];
    }

    /**
     * @param  Builder<Quiz>  $query
     */
    #[Scope]
    protected function published(Builder $query): void
    {
        $query->where('is_published', true);
    }
}
