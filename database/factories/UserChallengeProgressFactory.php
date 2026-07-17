<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\ChallengeStatus;
use App\Models\Challenge;
use App\Models\User;
use App\Models\UserChallengeProgress;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<UserChallengeProgress>
 */
final class UserChallengeProgressFactory extends Factory
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
            'challenge_id' => Challenge::factory(),
            'status' => ChallengeStatus::NotStarted,
            'best_score' => 0,
            'stars' => 0,
            'last_code' => null,
            'attempts' => 0,
            'completed_at' => null,
        ];
    }

    public function completed(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => ChallengeStatus::Completed,
            'best_score' => 100,
            'stars' => 3,
            'attempts' => 1,
            'completed_at' => now(),
        ]);
    }
}
