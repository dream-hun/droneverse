<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\Quiz;

/**
 * Remove a quiz, its questions, and every attempt and pass recorded on it.
 *
 * All of it cascades at the database level. Quizzes feed no rollup and no
 * leaderboard, so unlike a mission there is nothing derived to recompute.
 */
final readonly class DeleteQuiz
{
    public function handle(Quiz $quiz): void
    {
        $quiz->delete();
    }
}
