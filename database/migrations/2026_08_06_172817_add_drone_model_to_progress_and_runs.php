<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Which drone a pilot flies a mission in, and which one flew each run.
     *
     * Two columns that look alike and answer different questions, for the
     * same reason `user_challenge_progress` and `challenge_runs` are two
     * tables at all. The progress column is the pilot's standing choice on
     * this mission — it is what the cockpit restores when they come back, and
     * it is the only place the server will read a drone from when grading,
     * so a submission cannot name its own airframe. The run column is what
     * actually flew, kept per attempt, because a curve that mixes a Cadet's
     * runs with a Vector's without saying so is a curve that cannot be read.
     *
     * Both are nullable and both null out rather than cascade. A drone
     * retired from the fleet must not delete a pilot's progress or erase runs
     * from the analytics record; the row survives, and a null simply means
     * "the airframe of the day", which is what every row written before this
     * migration honestly is. App\Actions\ResolveMissionDrone reads null as
     * the fleet default, which is the drone those earlier runs were flown in.
     */
    public function up(): void
    {
        Schema::table('user_challenge_progress', function (Blueprint $table): void {
            $table->foreignId('drone_model_id')->nullable()->after('challenge_id')->constrained()->nullOnDelete();
        });

        Schema::table('challenge_runs', function (Blueprint $table): void {
            $table->foreignId('drone_model_id')->nullable()->after('challenge_id')->constrained()->nullOnDelete();
        });
    }
};
