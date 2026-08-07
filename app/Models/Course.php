<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Plan;
use Database\Factories\CourseFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $title
 * @property string $slug
 * @property string $description
 * @property string $difficulty
 * @property string $required_plan
 * @property int $order
 * @property bool $is_published
 * @property-read int|null $challenges_count
 */
#[Fillable(['title', 'slug', 'description', 'difficulty', 'required_plan', 'order', 'is_published'])]
final class Course extends Model
{
    /** @use HasFactory<CourseFactory> */
    use HasFactory;

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    /**
     * The tier this course's content belongs to.
     *
     * This is the default its missions inherit and the tier its catalog card
     * is badged with. It is deliberately not page access: the course page
     * stays open to everyone, because a Starter pilot reading the briefings
     * for missions they cannot fly yet is the whole conversion argument.
     * Access is enforced per mission, where the flying happens.
     *
     * A value that no longer names a plan falls back to Starter rather than
     * throwing — the catalog should degrade open, not break.
     */
    public function requiredPlan(): Plan
    {
        return Plan::tryFrom($this->required_plan) ?? Plan::Starter;
    }

    /**
     * @return HasMany<Challenge, $this>
     */
    public function challenges(): HasMany
    {
        return $this->hasMany(Challenge::class)->orderBy('order');
    }

    /**
     * The knowledge checks attached to this course.
     *
     * A course normally carries one, but this is a hasMany rather than a
     * hasOne: nothing in the schema or the routing forbids a second, the
     * quiz is addressed by its own slug within the course exactly as a
     * mission is, and a hasOne would have to be widened — along with every
     * caller — the first time a course wants a mid-course check as well as
     * a final one.
     *
     * @return HasMany<Quiz, $this>
     */
    public function quizzes(): HasMany
    {
        return $this->hasMany(Quiz::class)->orderBy('order');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_published' => 'boolean',
            'order' => 'integer',
        ];
    }

    /**
     * @param  Builder<Course>  $query
     */
    #[Scope]
    protected function published(Builder $query): void
    {
        $query->where('is_published', true);
    }

    /**
     * Scope to the browsable catalog: published courses in display order,
     * with their published-challenge counts.
     *
     * @param  Builder<Course>  $query
     */
    #[Scope]
    protected function catalog(Builder $query): void
    {
        $query->published()
            ->withCount(['challenges' => fn ($challenges) => $challenges->published()])
            ->orderBy('order');
    }
}
