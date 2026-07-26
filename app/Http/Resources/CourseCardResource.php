<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Course;
use Illuminate\Support\Collection;

/**
 * A course tile with the viewer's progress on it.
 *
 * The dashboard and the course catalog render the same card, so they share
 * this shape; the catalog adds a description on top via
 * {@see CourseCatalogResource}. Mirrors the `CourseSummary` type in
 * resources/js/types/simulator.ts.
 */
final class CourseCardResource
{
    /**
     * @param  Collection<int, Course>  $courses  courses loaded through {@see Course::catalog()}
     * @param  Collection<int, int>  $completedByCourse  completed counts keyed by course id
     * @return array<int, array{title: string, slug: string, difficulty: string, challengesCount: int, completedCount: int}>
     */
    public static function collection(Collection $courses, Collection $completedByCourse): array
    {
        return $courses
            ->map(fn (Course $course): array => self::one($course, $completedByCourse))
            ->all();
    }

    /**
     * @param  Collection<int, int>  $completedByCourse
     * @return array{title: string, slug: string, difficulty: string, challengesCount: int, completedCount: int}
     */
    public static function one(Course $course, Collection $completedByCourse): array
    {
        return [
            'title' => $course->title,
            'slug' => $course->slug,
            'difficulty' => $course->difficulty,
            'challengesCount' => (int) $course->challenges_count,
            'completedCount' => (int) $completedByCourse->get($course->id, 0),
        ];
    }
}
