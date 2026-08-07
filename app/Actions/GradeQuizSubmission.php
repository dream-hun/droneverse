<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\Quiz;
use App\Models\QuizQuestion;

/**
 * Score a set of chosen answers against a quiz's own answer key.
 *
 * The counterpart of {@see GradeSimulatorRun}, and it exists for the same
 * reason: the browser is never trusted with a result.
 * {@see \App\Http\Requests\StoreQuizAttemptRequest} does not accept a score, a
 * correct count or a claim of passing — it accepts which options were ticked,
 * and this decides what that was worth by reading `is_correct` server-side.
 *
 * Pure. It writes nothing and caches nothing, so it can grade a submission
 * for a response, for a report, or in a test without any of them differing.
 * {@see RecordQuizAttempt} is what persists the outcome.
 */
final readonly class GradeQuizSubmission
{
    /**
     * Grade a submission.
     *
     * The score is the percentage of questions answered correctly, rounded to
     * the nearest whole percent, and it is deliberately computed from the
     * quiz's *whole* question set rather than from the questions the pilot
     * happened to answer. Skipping a question has to cost the same as getting
     * it wrong, or leaving the hard ones blank would be the highest-scoring
     * way to take the quiz.
     *
     * A quiz with no questions scores zero and does not pass. There is no
     * honest percentage of nothing, and the alternative — treating an empty
     * quiz as vacuously passed — would hand every pilot a pass the moment an
     * author published a quiz before writing its questions.
     *
     * @param  array<int, array<int, int>>  $answers  selected option ids, keyed by question id
     * @return array{score: int, correctCount: int, questionCount: int, passed: bool, questions: array<int, array{id: int, correct: bool, selectedOptionIds: array<int, int>, correctOptionIds: array<int, int>, explanation: string|null}>}
     */
    public function handle(Quiz $quiz, array $answers): array
    {
        // Self-contained rather than relying on the caller's eager loading:
        // this runs from HTTP today and could run from a command or a job
        // tomorrow, and `loadMissing` costs nothing when the controller has
        // already loaded them — which it has, precisely so grading a whole
        // quiz does not fire a query per question.
        $quiz->loadMissing('questions.options');

        $questions = $quiz->questions
            ->map(function (QuizQuestion $question) use ($answers): array {
                $selected = array_values(array_unique($answers[$question->id] ?? []));

                return [
                    'id' => $question->id,
                    'correct' => $question->isAnsweredBy($selected),
                    'selectedOptionIds' => $selected,
                    'correctOptionIds' => $question->correctOptionIds(),
                    'explanation' => $question->explanation,
                ];
            })
            ->all();

        $questionCount = count($questions);
        $correctCount = count(array_filter($questions, static fn (array $question): bool => $question['correct']));

        $score = $questionCount === 0
            ? 0
            : (int) round($correctCount / $questionCount * 100);

        return [
            'score' => $score,
            'correctCount' => $correctCount,
            'questionCount' => $questionCount,
            'passed' => $questionCount > 0 && $quiz->isPassedBy($score),
            'questions' => $questions,
        ];
    }
}
