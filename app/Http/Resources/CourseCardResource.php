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
     * @return array<int, array{title: string, slug: string, difficulty: string, requiredPlan: string, missionPlan: string|null, locked: bool, challengesCount: int, freeChallengesCount: int, completedCount: int}>
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
     * @return array{title: string, slug: string, difficulty: string, requiredPlan: string, missionPlan: string|null, locked: bool, challengesCount: int, freeChallengesCount: int, completedCount: int}
     */
    public static function one(Course $course, Collection $completedByCourse, Plan $viewerPlan): array
    {
        $requiredPlan = $course->requiredPlan();
        $challenges = (int) $course->challenges_count;
        $free = (int) $course->free_challenges_count;

        return [
            'title' => $course->title,
            'slug' => $course->slug,
            'difficulty' => $course->difficulty,
            'requiredPlan' => $requiredPlan->value,
            'missionPlan' => self::missionPlan($course, $requiredPlan, $free, $challenges)?->value,
            'locked' => ! $viewerPlan->covers($requiredPlan),
            'challengesCount' => $challenges,
            'freeChallengesCount' => $free,
            'completedCount' => (int) $completedByCourse->get($course->id, 0),
        ];
    }

    /**
     * The plan the missions this course charges for actually need, or null
     * when it charges for none of them.
     *
     * `mission_plan` is only set when a mission states a tier of its own, so a
     * paid course whose missions all inherit falls back to the course's own
     * tier — but only when there is something left for that tier to cover. A
     * course every mission of which is free charges for nothing, whatever the
     * row itself says.
     */
    private static function missionPlan(Course $course, Plan $requiredPlan, int $free, int $challenges): ?Plan
    {
        $stated = Plan::tryFrom($course->mission_plan ?? '');

        if ($stated instanceof Plan) {
            return $stated;
        }

        return $requiredPlan->isPaid() && $free < $challenges ? $requiredPlan : null;
    }
}
