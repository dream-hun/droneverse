<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Enums\QuizStatus;
use App\Models\UserQuizProgress;

/**
 * The viewer's standing on one quiz, as the quiz page reads it.
 *
 * A pilot opening a quiz for the first time has no progress row, and the
 * defaults here are the single place that reads as "not started". Mirrors the
 * `QuizProgress` type in resources/js/types/quiz.ts.
 */
final class QuizProgressResource
{
    /**
     * @return array{status: string, bestScore: int, attempts: int, passed: bool}
     */
    public static function one(?UserQuizProgress $progress): array
    {
        $status = $progress?->status() ?? QuizStatus::NotStarted;

        return [
            'status' => $status->value,
            'bestScore' => $progress->best_score ?? 0,
            'attempts' => $progress->attempts ?? 0,
            /*
             * Sent alongside the status rather than derived from it on the
             * client. Every screen that shows a quiz asks "has this pilot
             * passed" far more often than it asks which of three states they
             * are in, and a boolean the server computed cannot drift from the
             * enum the way a repeated `=== 'passed'` comparison can.
             */
            'passed' => $status === QuizStatus::Passed,
        ];
    }
}
