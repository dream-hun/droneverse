<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Challenge;
use App\Models\DronePhoto;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DronePhoto>
 */
final class DronePhotoFactory extends Factory
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
            'label' => fake()->optional()->word(),
            'path' => 'drone-photos/'.fake()->uuid().'.jpg',
            'position' => [
                'x' => fake()->randomFloat(1, -20, 20),
                'y' => fake()->randomFloat(1, 1, 12),
                'z' => fake()->randomFloat(1, -30, 0),
                'headingDeg' => fake()->randomFloat(1, 0, 359),
            ],
        ];
    }
}
