<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Challenge;
use App\Models\ChallengeRun;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ChallengeRun>
 */
final class ChallengeRunFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * A run that fell short by default, because that is what most runs are
     * and because a test asserting on improvement needs somewhere to improve
     * from.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $total = $this->faker->numberBetween(2, 5);

        return [
            'user_id' => User::factory(),
            'challenge_id' => Challenge::factory(),
            'score' => $this->faker->numberBetween(0, 60),
            'stars' => 0,
            'completed' => false,
            'objectives_hit' => $this->faker->numberBetween(0, $total - 1),
            'objectives_total' => $total,
            'collisions' => $this->faker->numberBetween(0, 3),
            'elapsed_seconds' => $this->faker->randomFloat(2, 5, 90),
            'landed' => $this->faker->boolean(),
            'timed_out' => false,
        ];
    }

    /**
     * A run that cleared the mission.
     */
    public function completed(): static
    {
        return $this->state(fn (array $attributes): array => [
            'score' => 100,
            'stars' => 3,
            'completed' => true,
            'objectives_hit' => $attributes['objectives_total'],
            'collisions' => 0,
            'landed' => true,
            'timed_out' => false,
        ]);
    }

    /**
     * A run worth a specific number of points.
     */
    public function scoring(int $score): static
    {
        return $this->state(fn (array $attributes): array => ['score' => $score]);
    }
}
