<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\QuizQuestionType;
use App\Models\QuizQuestion;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Revise a question, its explanation or its answers.
 *
 * Fixing the answer key does not re-grade anybody: attempts keep a score, not
 * a set of choices, so there is nothing to re-grade from. The corrected key
 * applies from the next attempt.
 */
final readonly class UpdateQuizQuestion
{
    public function __construct(private SyncQuizOptions $options) {}

    /**
     * @param  array{prompt: string, explanation: string|null, order: int, options: array<int, array{id: int|null, label: string, is_correct: bool}>}  $attributes
     *
     * @throws Throwable
     */
    public function handle(QuizQuestion $question, array $attributes): QuizQuestion
    {
        return DB::transaction(function () use ($question, $attributes): QuizQuestion {
            $question->update([
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
