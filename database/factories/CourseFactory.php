<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\Plan;
use App\Models\Course;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Course>
 */
final class CourseFactory extends Factory
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
            'title' => ucwords($title),
            'slug' => (string) str($title)->slug(),
            'description' => fake()->paragraph(),
            'difficulty' => fake()->randomElement(['beginner', 'intermediate', 'advanced']),
            'required_plan' => Plan::Starter->value,
            'order' => fake()->numberBetween(0, 10),
            'is_published' => true,
        ];
    }

    public function unpublished(): static
    {
        return $this->state(fn (array $attributes): array => [
            'is_published' => false,
        ]);
    }

    /**
     * Put the course behind a plan. Its missions inherit unless they say
     * otherwise.
     */
    public function requiring(Plan $plan): static
    {
        return $this->state(fn (array $attributes): array => [
            'required_plan' => $plan->value,
        ]);
    }
}
