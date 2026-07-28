<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Enums\Plan;
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
     * @return array<int, array{title: string, slug: string, difficulty: string, requiredPlan: string, locked: bool, challengesCount: int, completedCount: int}>
     */
    public static function collection(Collection $courses, Collection $completedByCourse, Plan $viewerPlan): array
    {
        return $courses
            ->map(fn (Course $course): array => self::one($course, $completedByCourse, $viewerPlan))
            ->all();
    }

    /**
     * `locked` says the viewer's plan does not reach this course's tier, which
     * badges the card and softens its call to action. It does not stop the card
     * being shown or followed: the course page stays open to everyone, because
     * a pilot reading the briefings for missions they cannot fly yet is the
     * whole conversion argument. A hidden card converts nobody.
     *
     * @param  Collection<int, int>  $completedByCourse
     * @return array{title: string, slug: string, difficulty: string, requiredPlan: string, locked: bool, challengesCount: int, completedCount: int}
     */
    public static function one(Course $course, Collection $completedByCourse, Plan $viewerPlan): array
    {
        $requiredPlan = $course->requiredPlan();

        return [
            'title' => $course->title,
            'slug' => $course->slug,
            'difficulty' => $course->difficulty,
            'requiredPlan' => $requiredPlan->value,
            'locked' => ! $viewerPlan->covers($requiredPlan),
            'challengesCount' => (int) $course->challenges_count,
            'completedCount' => (int) $completedByCourse->get($course->id, 0),
        ];
    }
}
