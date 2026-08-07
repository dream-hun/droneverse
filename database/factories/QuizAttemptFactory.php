<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Quiz;
use App\Models\QuizAttempt;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<QuizAttempt>
 */
final class QuizAttemptFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * A submission that fell short, because that is what most first attempts
     * are and because a test asserting on improvement needs somewhere to
     * improve from.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $questionCount = $this->faker->numberBetween(4, 8);
        $correct = $this->faker->numberBetween(0, (int) floor($questionCount / 2));

        return [
            'user_id' => User::factory(),
            'quiz_id' => Quiz::factory(),
            'score' => (int) round($correct / $questionCount * 100),
            'correct_count' => $correct,
            'question_count' => $questionCount,
            'passed' => false,
        ];
    }

    public function passed(): static
    {
        return $this->state(fn (array $attributes): array => [
            'score' => 100,
            'correct_count' => $attributes['question_count'],
            'passed' => true,
        ]);
    }
}
