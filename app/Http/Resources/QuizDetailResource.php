<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Enums\QuizQuestionType;
use App\Models\Quiz;
use App\Models\QuizOption;
use App\Models\QuizQuestion;

/**
 * Everything the quiz page needs to ask the questions — and nothing it needs
 * to answer them.
 *
 * Note what is absent. `is_correct` never travels in this shape, and neither
 * does a question's `explanation`: an explanation says *why* an answer is
 * right, which on a well-written question gives the answer away as surely as
 * the flag does. Both ship only through {@see QuizResultResource}, after the
 * server has graded a submission and there is nothing left to give away.
 *
 * This is the same arrangement {@see ChallengeDetailResource} has with
 * `solution_code`, and it is the shape the rule "the browser is never trusted
 * with a result" takes for a quiz. Mirrors the `QuizDetail` type in
 * resources/js/types/quiz.ts.
 */
final class QuizDetailResource
{
    /**
     * @return array{title: string, slug: string, description: string, passPercentage: int, questions: array<int, array{id: int, prompt: string, type: string, allowsMultiple: bool, options: array<int, array{id: int, label: string}>}>}
     */
    public static function one(Quiz $quiz): array
    {
        $quiz->loadMissing('questions.options');

        return [
            'title' => $quiz->title,
            'slug' => $quiz->slug,
            'description' => $quiz->description,
            'passPercentage' => $quiz->pass_percentage,
            'questions' => $quiz->questions
                ->map(fn (QuizQuestion $question): array => [
                    'id' => $question->id,
                    'prompt' => $question->prompt,
                    'type' => $question->type->value,
                    /*
                     * Sent as its own flag rather than left for the client to
                     * derive from `type`. The page has to choose radios or
                     * checkboxes, and a widget that disagrees with the grader
                     * about how many answers a question takes is a question
                     * the pilot cannot pass — so the server states it outright
                     * instead of leaving a string comparison to drift.
                     */
                    'allowsMultiple' => $question->type->allowsMultipleAnswers(),
                    'options' => $question->options
                        ->map(fn (QuizOption $option): array => [
                            'id' => $option->id,
                            'label' => $option->label,
                        ])
                        ->all(),
                ])
                ->all(),
        ];
    }

    /**
     * The question types this shape can carry, for readers checking the
     * client union stays in step.
     *
     * @return array<int, string>
     */
    public static function questionTypes(): array
    {
        return array_map(
            static fn (QuizQuestionType $type): string => $type->value,
            QuizQuestionType::cases(),
        );
    }
}
