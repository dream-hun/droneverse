<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\QuizOption;
use App\Models\QuizQuestion;

/**
 * Make a question's options exactly the given list, in the given order.
 *
 * Options that arrive with the id of one already on this question are updated
 * in place rather than replaced. That matters to a pilot with the quiz open:
 * their answers name option ids, and an edit that fixed a typo by deleting and
 * recreating every option would quietly mark their correct answer wrong. An
 * id that names no option on this question — stale, or somebody else's — is
 * treated as a new option rather than trusted.
 *
 * Options missing from the list are deleted. Nothing stores which options a
 * pilot chose — attempts keep only the score — so there is no history to lose.
 */
final readonly class SyncQuizOptions
{
    /**
     * @param  array<int, array{id: int|null, label: string, is_correct: bool}>  $options
     */
    public function handle(QuizQuestion $question, array $options): void
    {
        $existing = $question->options()->get()->keyBy('id');
        $kept = [];

        foreach (array_values($options) as $order => $option) {
            $attributes = [
                'label' => $option['label'],
                'is_correct' => $option['is_correct'],
                'order' => $order,
            ];

            $current = $option['id'] === null ? null : $existing->get($option['id']);

            if ($current instanceof QuizOption) {
                $current->update($attributes);
                $kept[] = $current->id;

                continue;
            }

            $kept[] = $question->options()->create($attributes)->id;
        }

        $question->options()->whereNotIn('id', $kept)->delete();
    }
}
