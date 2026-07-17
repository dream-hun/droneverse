<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Challenge;
use App\Models\Course;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Challenge>
 */
final class ChallengeFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $title = sprintf('%s %s %s', fake()->word(), fake()->word(), fake()->unique()->word());

        return [
            'course_id' => Course::factory(),
            'title' => ucwords($title),
            'slug' => (string) str($title)->slug(),
            'briefing' => fake()->paragraphs(2, true),
            'order' => fake()->numberBetween(0, 10),
            'difficulty' => fake()->randomElement(['beginner', 'intermediate', 'advanced']),
            'starter_code' => "async function main(drone) {\n  await drone.takeoff();\n  await drone.land();\n}\n",
            'environment' => [
                'start' => ['x' => 0, 'y' => 0.5, 'z' => 0],
                'obstacles' => [],
                'gates' => [],
                'waypoints' => [],
            ],
            'success_criteria' => [
                'type' => 'waypoints',
                'waypoints' => [],
                'avoid_collisions' => true,
                'max_time_seconds' => 60,
                'landing_required' => true,
            ],
            'max_score' => 100,
            'is_published' => true,
        ];
    }

    public function unpublished(): static
    {
        return $this->state(fn (array $attributes): array => [
            'is_published' => false,
        ]);
    }
}
