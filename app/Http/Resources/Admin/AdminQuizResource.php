<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin;

use App\Models\Quiz;
use Illuminate\Support\Collection;

/**
 * A quiz as the course admin page lists it, and as its edit form is filled.
 *
 * @phpstan-type Row array{slug: string, title: string, description: string, order: int, requiredPlan: string|null, passPercentage: int, isPublished: bool, questions: int, pilots: int}
 */
final class AdminQuizResource
{
    /**
     * @param  Collection<int, Quiz>  $quizzes  with `questions` and `progress` counted
     * @return array<int, Row>
     */
    public static function collection(Collection $quizzes): array
    {
        return $quizzes->map(fn (Quiz $quiz): array => self::one($quiz))->values()->all();
    }

    /**
     * @return Row
     */
    public static function one(Quiz $quiz): array
    {
        return [
            'slug' => $quiz->slug,
            'title' => $quiz->title,
            'description' => $quiz->description,
            'order' => $quiz->order,
            'requiredPlan' => $quiz->required_plan,
            'passPercentage' => $quiz->pass_percentage,
            'isPublished' => $quiz->is_published,
            'questions' => $quiz->questions_count ?? 0,
            'pilots' => $quiz->progress_count ?? 0,
        ];
    }
}
