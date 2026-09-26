<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin;

use App\Models\QuizOption;
use App\Models\QuizQuestion;
use Illuminate\Support\Collection;

/**
 * A question with its answer key, for the admin quiz editor.
 *
 * The one place `is_correct` leaves the server outside a graded result. It is
 * hidden on the model so no pilot-facing path can serialize it by accident,
 * and read out explicitly here because this is the screen the key is written
 * on — behind `manage_courses`, which is what makes that safe.
 *
 * @phpstan-type Row array{uuid: string, prompt: string, explanation: string|null, order: int, type: string, options: array<int, array{id: int, label: string, isCorrect: bool}>}
 */
final class AdminQuizQuestionResource
{
    /**
     * @param  Collection<int, QuizQuestion>  $questions  with `options` eager loaded
     * @return array<int, Row>
     */
    public static function collection(Collection $questions): array
    {
        return $questions->map(fn (QuizQuestion $question): array => [
            'uuid' => $question->uuid,
            'prompt' => $question->prompt,
            'explanation' => $question->explanation,
            'order' => $question->order,
            'type' => $question->type->value,
            'options' => $question->options
                ->map(fn (QuizOption $option): array => [
                    'id' => $option->id,
                    'label' => $option->label,
                    'isCorrect' => $option->is_correct,
                ])
                ->values()
                ->all(),
        ])->values()->all();
    }
}
