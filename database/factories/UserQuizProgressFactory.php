<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Quiz;
use App\Models\User;
use App\Models\UserQuizProgress;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<UserQuizProgress>
 */
final class UserQuizProgressFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'quiz_id' => Quiz::factory(),
            'best_score' => 0,
            'attempts' => 0,
            'passed_at' => null,
        ];
    }

    /**
     * A pilot who has tried and fallen short.
     */
    public function attempted(int $bestScore = 40): static
    {
        return $this->state(fn (array $attributes): array => [
            'best_score' => $bestScore,
            'attempts' => 1,
            'passed_at' => null,
        ]);
    }

    public function passed(int $bestScore = 100): static
    {
        return $this->state(fn (array $attributes): array => [
            'best_score' => $bestScore,
            'attempts' => 1,
            'passed_at' => now(),
        ]);
    }
}
