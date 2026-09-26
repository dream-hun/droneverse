<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\QuizQuestion;

/**
 * Remove a question and its answers from a quiz.
 *
 * Pilots who already passed keep their pass; the percentage of the questions
 * that remain is what counts from their next attempt.
 */
final readonly class DeleteQuizQuestion
{
    public function handle(QuizQuestion $question): void
    {
        $question->delete();
    }
}
