<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\UserQuizProgress;

/**
 * A graded submission, as the pilot is finally shown it.
 *
 * The one shape that carries the answer key, and it is only ever built after
 * a submission has been graded — at which point telling the pilot which
 * answer was right, and why, is the entire point of having asked. Everything
 * withheld by {@see QuizDetailResource} appears here: the correct option ids,
 * and the explanation behind each question.
 *
 * The per-question rows are returned in the quiz's own question order, which
 * is the order the page rendered them in, so the client can zip the review
 * straight onto what the pilot is already looking at.
 *
 * Mirrors the `QuizResult` type in resources/js/types/quiz.ts.
 */
final class QuizResultResource
{
    /**
     * @param  array{score: int, correctCount: int, questionCount: int, passed: bool, questions: array<int, array{id: int, correct: bool, selectedOptionIds: array<int, int>, correctOptionIds: array<int, int>, explanation: string|null}>}  $result  as returned by \App\Actions\GradeQuizSubmission
     * @return array{score: int, correctCount: int, questionCount: int, passed: bool, questions: array<int, array{id: int, correct: bool, selectedOptionIds: array<int, int>, correctOptionIds: array<int, int>, explanation: string|null}>, progress: array{status: string, bestScore: int, attempts: int, passed: bool}}
     */
    public static function one(array $result, UserQuizProgress $progress): array
    {
        return [
            'score' => $result['score'],
            'correctCount' => $result['correctCount'],
            'questionCount' => $result['questionCount'],
            'passed' => $result['passed'],
            'questions' => $result['questions'],
            /*
             * The merged standing travels back with the result because this
             * submission may not have been the pilot's best, and the two
             * answer different questions: `passed` above is whether *this*
             * attempt cleared the bar, `progress.passed` is whether the pilot
             * has ever cleared it. A page that only had the first would tell
             * a pilot reviewing a passed quiz that they had just failed it.
             */
            'progress' => QuizProgressResource::one($progress),
        ];
    }
}
