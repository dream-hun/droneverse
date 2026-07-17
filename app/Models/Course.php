<?php

declare(strict_types=1);

namespace App\Models;

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
 * @property int $order
 * @property bool $is_published
 * @property-read int|null $challenges_count
 */
#[Fillable(['title', 'slug', 'description', 'difficulty', 'order', 'is_published'])]
final class Course extends Model
{
    /** @use HasFactory<CourseFactory> */
    use HasFactory;

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    /**
     * @return HasMany<Challenge, $this>
     */
    public function challenges(): HasMany
    {
        return $this->hasMany(Challenge::class)->orderBy('order');
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
