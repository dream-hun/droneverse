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
            'label' => $this->faker->optional()->word(),
            'path' => 'drone-photos/'.$this->faker->uuid().'.jpg',
            'position' => [
                'x' => $this->faker->randomFloat(1, -20, 20),
                'y' => $this->faker->randomFloat(1, 1, 12),
                'z' => $this->faker->randomFloat(1, -30, 0),
                'headingDeg' => $this->faker->randomFloat(1, 0, 359),
            ],
        ];
    }
}
