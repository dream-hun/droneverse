<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Course;
use Illuminate\Support\Collection;

/**
 * A course as the landing page advertises it.
 *
 * Deliberately narrower than {@see CourseCatalogResource}: the landing page
 * is the one page every guest sees first, and it has no viewer whose progress
 * it could report. Mirrors the `MarketingCourse` type in
 * resources/js/types/simulator.ts.
 */
final class CourseMarketingResource
{
    /**
     * @param  Collection<int, Course>  $courses  courses loaded through {@see Course::catalog()}
     * @return array<int, array{title: string, slug: string, description: string, difficulty: string, challengesCount: int}>
     */
    public static function collection(Collection $courses): array
    {
        return $courses
            ->map(fn (Course $course): array => [
                'title' => $course->title,
                'slug' => $course->slug,
                'description' => $course->description,
                'difficulty' => $course->difficulty,
                'challengesCount' => (int) $course->challenges_count,
            ])
            ->all();
    }
}
