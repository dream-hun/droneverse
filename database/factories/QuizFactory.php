<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\Plan;
use App\Models\Course;
use App\Models\Quiz;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Quiz>
 */
final class QuizFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $title = sprintf('%s %s Check', ucfirst(fake()->word()), fake()->unique()->word());

        return [
            'course_id' => Course::factory(),
            'title' => $title,
            'slug' => (string) str($title)->slug(),
            'description' => fake()->paragraph(),
            'order' => 0,
            'required_plan' => null,
            'pass_percentage' => 70,
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
     * Put the quiz behind a plan of its own, overriding its course's.
     */
    public function requiring(Plan $plan): static
    {
        return $this->state(fn (array $attributes): array => [
            'required_plan' => $plan->value,
        ]);
    }

    /**
     * Move the bar, for tests that care where it sits.
     */
    public function passingAt(int $percentage): static
    {
        return $this->state(fn (array $attributes): array => [
            'pass_percentage' => $percentage,
        ]);
    }

    /**
     * Give the quiz `$count` single-answer questions, each with one correct
     * option and three distractors.
     *
     * The shape most tests want: enough questions that a percentage score is
     * meaningful, and a predictable one-correct-of-four so a test can pick the
     * right answer without knowing which option it landed on.
     */
    public function withQuestions(int $count = 4): static
    {
        return $this->afterCreating(function (Quiz $quiz) use ($count): void {
            foreach (range(0, $count - 1) as $order) {
                QuizQuestionFactory::new()
                    ->for($quiz)
                    ->withOptions()
                    ->create(['order' => $order]);
            }
        });
    }
}
