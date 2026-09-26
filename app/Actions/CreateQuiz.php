<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\Course;
use App\Models\Quiz;

/**
 * Add a knowledge check to a course.
 *
 * A quiz with no questions scores nothing and passes nobody — see
 * App\Actions\GradeQuizSubmission — so publishing one before its questions are
 * written is harmless, if unhelpful. The editor opens unpublished by default.
 */
final readonly class CreateQuiz
{
    /**
     * @param  array{title: string, slug: string, description: string, order: int, required_plan: string|null, pass_percentage: int, is_published: bool}  $attributes
     */
    public function handle(Course $course, array $attributes): Quiz
    {
        return $course->quizzes()->create($attributes);
    }
}
