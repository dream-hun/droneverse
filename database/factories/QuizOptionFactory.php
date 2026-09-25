<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\QuizOption;
use App\Models\QuizQuestion;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<QuizOption>
 */
final class QuizOptionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * Wrong by default. A factory that produced correct options would make
     * every generated question unanswerable-by-accident-correctly, and the
     * states that matter are the ones set deliberately.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'quiz_question_id' => QuizQuestion::factory(),
            'label' => fake()->sentence(4),
            'is_correct' => false,
            'order' => 0,
        ];
    }

    public function correct(): static
    {
        return $this->state(fn (array $attributes): array => [
            'is_correct' => true,
        ]);
    }
}
