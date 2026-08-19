<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Course;
use App\Models\PilotCourseTotals;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PilotCourseTotals>
 */
final class PilotCourseTotalsFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * The same caveat as {@see PilotMissionStatsFactory}: this row is derived
     * from progress and maintained by
     * {@see \App\Observers\UserChallengeProgressObserver}, so a test that
     * wants a pilot on the board should create their progress and let the
     * total follow. This is for the tests that are about the rollup itself.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'course_id' => Course::factory(),
            'points' => 0,
            'stars' => 0,
            'completed' => 0,
            'finished_at' => null,
        ];
    }
}
