<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\QuizQuestionType;
use App\Models\Quiz;
use App\Models\QuizQuestion;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Add a question, and its answers, to a quiz.
 *
 * Adding a question to a published quiz does not move anybody's standing: the
 * pass mark is a percentage and a pilot's best is kept as they earned it, so
 * the new question counts from their next attempt.
 */
final readonly class CreateQuizQuestion
{
    public function __construct(private SyncQuizOptions $options) {}

    /**
     * @param  array{prompt: string, explanation: string|null, order: int, options: array<int, array{id: int|null, label: string, is_correct: bool}>}  $attributes
     *
     * @throws Throwable
     */
    public function handle(Quiz $quiz, array $attributes): QuizQuestion
    {
        return DB::transaction(function () use ($quiz, $attributes): QuizQuestion {
            $question = $quiz->questions()->create([
                'prompt' => $attributes['prompt'],
                'explanation' => $attributes['explanation'],
                'order' => $attributes['order'],
                'type' => QuizQuestionType::forCorrectAnswers(
                    count(array_filter($attributes['options'], static fn (array $option): bool => $option['is_correct'])),
                ),
            ]);

            $this->options->handle($question, $attributes['options']);

            return $question;
        });
    }
}
