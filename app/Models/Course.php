<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Plan;
use Database\Factories\CourseFactory;
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
 * @property-read int|null $free_challenges_count
 * @property-read int|null $published_challenges_count
 * @property-read int|null $quizzes_count
 * @property-read string|null $mission_plan
 */
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
     * Scope to the browsable catalog: published courses in display order, with
     * the counts and the tier a catalog card is drawn from.
     *
     * Three aggregates rather than one, because "how many missions" is not the
     * question a visitor arrives with. `free_challenges_count` is how many of
     * them cost nothing, and `mission_plan` is the tier the rest sit in. A
     * course's own `required_plan` answers neither: it is the default its
     * missions inherit, not what they charge, and
     * {@see \App\Concerns\CourseContent::requiredPlanIn()} exists precisely so
     * that Precision Flight can be a browsable Starter course whose every
     * mission is Pro. A card badged from `required_plan` alone advertises that
     * course as free to fly, which it is not.
     *
     * Both extra clauses are correlated subqueries against the outer `courses`
     * row. That correlation is the point: it resolves the inherited case — a
     * mission stating no tier of its own — in SQL, rather than by loading every
     * mission in the catalog to ask each one.
     *
     * `mission_plan` takes the first stated tier it finds rather than the
     * highest. Nothing sells a course mixing two paid tiers, and if one ever
     * ships, naming either of them is a guess; this is the cheap answer and it
     * is documented as such rather than dressed up with an ordering that would
     * imply more than it knows.
     *
     * @param  Builder<Course>  $query
     */
    #[Scope]
    protected function catalog(Builder $query): void
    {
        $query->published()
            ->withCount([
                'challenges' => $this->publishedChallenges(...),
                'challenges as free_challenges_count' => $this->freeChallenges(...),
            ])
            ->addSelect(['mission_plan' => Challenge::query()
                ->published()
                ->whereColumn('challenges.course_id', 'courses.id')
                ->whereNotNull('challenges.required_plan')
                ->where('challenges.required_plan', '!=', Plan::Starter->value)
                ->select('challenges.required_plan')
                ->limit(1),
            ])
            ->orderBy('order');
    }

    /**
     * The missions a catalog card counts.
     *
     * @param  Builder<Challenge>  $challenges
     */
    private function publishedChallenges(Builder $challenges): void
    {
        $challenges->published();
    }

    /**
     * The missions a Starter pilot can fly: those stating Starter, and those
     * stating nothing inside a Starter course.
     *
     * @param  Builder<Challenge>  $challenges
     */
    private function freeChallenges(Builder $challenges): void
    {
        $challenges->published()
            ->where(fn (Builder $tier): Builder => $tier
                ->where('challenges.required_plan', Plan::Starter->value)
                ->orWhere(fn (Builder $inherited): Builder => $inherited
                    ->whereNull('challenges.required_plan')
                    ->where('courses.required_plan', Plan::Starter->value)));
    }
}
