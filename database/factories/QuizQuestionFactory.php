<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\QuizQuestionType;
use App\Models\Quiz;
use App\Models\QuizQuestion;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<QuizQuestion>
 */
final class QuizQuestionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'quiz_id' => Quiz::factory(),
            'prompt' => mb_rtrim(fake()->sentence(), '.').'?',
            'type' => QuizQuestionType::Single,
            'explanation' => fake()->sentence(),
            'order' => 0,
        ];
    }

    public function multiple(): static
    {
        return $this->state(fn (array $attributes): array => [
            'type' => QuizQuestionType::Multiple,
        ]);
    }

    /**
     * Attach four options, of which `$correct` are correct.
     *
     * The correct ones are always the first `$correct` by `order`, which is
     * what lets a test answer a generated quiz correctly without reading the
     * answer key back out of the database.
     */
    public function withOptions(int $correct = 1): static
    {
        return $this->afterCreating(function (QuizQuestion $question) use ($correct): void {
            foreach (range(0, 3) as $order) {
                QuizOptionFactory::new()
                    ->for($question, 'question')
                    ->create([
                        'order' => $order,
                        'is_correct' => $order < $correct,
                    ]);
            }
        });
    }
}
