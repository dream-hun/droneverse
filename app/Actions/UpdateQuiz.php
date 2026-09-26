<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\Quiz;

/**
 * Edit a quiz's details or its pass mark.
 *
 * Raising the pass mark does not take a pass away. A pilot's pass is recorded
 * as a moment, not re-derived from their score, so it stands; the new bar
 * applies to anybody who has not cleared the old one yet.
 */
final readonly class UpdateQuiz
{
    /**
     * @param  array{title: string, slug: string, description: string, order: int, required_plan: string|null, pass_percentage: int, is_published: bool}  $attributes
     */
    public function handle(Quiz $quiz, array $attributes): Quiz
    {
        $quiz->update($attributes);

        return $quiz;
    }
}
