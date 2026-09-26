<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin;

use App\Models\Course;
use Illuminate\Support\Collection;

/**
 * A course as the admin catalogue lists it, and as its edit form is filled.
 *
 * Unlike the public catalogue card, this shows an unpublished course as it
 * is and names the tier stored on it rather than the tier its missions charge:
 * this is the screen those values are edited on.
 *
 * @phpstan-type Row array{slug: string, title: string, description: string, difficulty: string, requiredPlan: string, order: int, isPublished: bool, challenges: int, publishedChallenges: int, quizzes: int}
 */
final class AdminCourseResource
{
    /**
     * @param  Collection<int, Course>  $courses  with the three counts loaded
     * @return array<int, Row>
     */
    public static function collection(Collection $courses): array
    {
        return $courses->map(fn (Course $course): array => self::one($course))->values()->all();
    }

    /**
     * @return Row
     */
    public static function one(Course $course): array
    {
        return [
            'slug' => $course->slug,
            'title' => $course->title,
            'description' => $course->description,
            'difficulty' => $course->difficulty,
            'requiredPlan' => $course->required_plan,
            'order' => $course->order,
            'isPublished' => $course->is_published,
            'challenges' => $course->challenges_count ?? 0,
            'publishedChallenges' => $course->published_challenges_count ?? 0,
            'quizzes' => $course->quizzes_count ?? 0,
        ];
    }
}
