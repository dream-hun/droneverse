<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Enums\Plan;
use App\Models\Course;
use Illuminate\Support\Collection;

/**
 * A {@see CourseCardResource} plus the blurb the catalog page shows.
 *
 * The dashboard deliberately omits the description: its cards are compact,
 * and the text would be dead weight on every dashboard payload.
 */
final class CourseCatalogResource
{
    /**
     * @param  Collection<int, Course>  $courses  courses loaded through {@see Course::catalog()}
     * @param  Collection<int, int>  $completedByCourse  completed counts keyed by course id
     * @return array<int, array{title: string, slug: string, description: string, difficulty: string, requiredPlan: string, missionPlan: string|null, locked: bool, challengesCount: int, freeChallengesCount: int, completedCount: int}>
     */
    public static function collection(Collection $courses, Collection $completedByCourse, Plan $viewerPlan): array
    {
        return $courses
            ->map(fn (Course $course): array => [
                ...CourseCardResource::one($course, $completedByCourse, $viewerPlan),
                'description' => $course->description,
            ])
            ->all();
    }
}
