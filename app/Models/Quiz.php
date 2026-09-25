<?php

declare(strict_types=1);

namespace App\Models;

use App\Concerns\CourseContent;
use Database\Factories\QuizFactory;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A knowledge check attached to a course.
 *
 * The catalog's second half. A {@see Challenge} asks whether a pilot can make
 * the drone do something; a quiz asks whether they understood why. Both are
 * authored content hanging off a {@see Course}, both are gated the same way —
 * which is now {@see CourseContent} rather than a second copy of the rules —
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
 * @property-read Course $course
 */
final class Quiz extends Model
{
    use CourseContent;

    /** @use HasFactory<QuizFactory> */
    use HasFactory;

    public function getRouteKeyName(): string
    {
        return 'slug';
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
