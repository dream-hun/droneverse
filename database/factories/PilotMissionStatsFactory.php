<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Challenge;
use App\Models\PilotMissionStats;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PilotMissionStats>
 */
final class PilotMissionStatsFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * Almost nothing should use this. The rollup is derived data and
     * {@see \App\Observers\ChallengeRunObserver} fills it in from the runs, so
     * a test that wants a pilot with history should create the runs and let
     * the totals follow — that is the path production takes, and building the
     * row by hand is how a test comes to pass against numbers the real code
     * would never produce. It exists for the tests that are *about* the
     * rollup, chiefly the one that plants a wrong row and asserts a rebuild
     * corrects it.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'challenge_id' => Challenge::factory(),
            'runs' => 0,
            'clean_runs' => 0,
            'cleared' => false,
            'best_score' => 0,
            'collisions_total' => 0,
            'elapsed_seconds_total' => 0,
            'attempts_to_clear' => null,
            'last_run_id' => null,
        ];
    }
}
