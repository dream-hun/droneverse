<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\Course;

/**
 * Add a course to the catalogue.
 *
 * Nothing to invalidate: a course nobody has flown is in nobody's totals, and
 * the leaderboard joins courses at read time, so a new one appears there only
 * once somebody has flown a mission in it.
 */
final readonly class CreateCourse
{
    /**
     * @param  array{title: string, slug: string, description: string, difficulty: string, required_plan: string, order: int, is_published: bool}  $attributes
     */
    public function handle(array $attributes): Course
    {
        return Course::query()->create($attributes);
    }
}
