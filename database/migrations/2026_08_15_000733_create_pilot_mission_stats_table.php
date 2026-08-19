<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * What one pilot's runs on one mission add up to.
     *
     * `challenge_runs` is append-only and never pruned, which is the point of
     * it — the attempt curve, the audit trail and any future correction all
     * need the individual flights. But four of the five analytics slices are
     * not asking about a flight; they are asking about a *total*, and they
     * were answering by aggregating every run a pilot had ever flown, on
     * every page load that missed the cache. The cost of reading a pilot's
     * summary therefore grew with how much they had flown, which is exactly
     * backwards: the pilots who use the simulator most are the ones the page
     * is for.
     *
     * This table is that aggregate, kept up to date as runs land instead of
     * recomputed on demand. It is derived data and nothing but derived data:
     * every column can be recomputed from the runs, `rollups:rebuild` does
     * exactly that, and a test asserts the incremental path and the rebuild
     * agree. If the two ever diverge, the runs win.
     *
     * Bounded by pilots times missions-they-have-flown rather than by runs,
     * so a pilot's two hundredth attempt costs the same to read as their
     * second.
     *
     * Deliberately keyed by mission rather than by course. The read models
     * only ever count content that is still playable, and keeping the
     * challenge id here means that filter stays a live join against
     * `challenges` and `courses` — retiring a mission takes it out of every
     * pilot's analytics the moment it happens, with no rollup to rebuild.
     */
    public function up(): void
    {
        Schema::create('pilot_mission_stats', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('challenge_id')->constrained()->cascadeOnDelete();

            $table->unsignedInteger('runs')->default(0);

            /** Runs that touched nothing, for the clean-run rate. */
            $table->unsignedInteger('clean_runs')->default(0);

            /** Whether any run on this mission was graded as completed. */
            $table->boolean('cleared')->default(false);

            $table->unsignedInteger('best_score')->default(0);

            /**
             * Summed rather than averaged so the mean stays exact as runs are
             * added. Storing the average would make every update a lossy
             * re-weighting of a number that had already been rounded.
             */
            $table->unsignedBigInteger('collisions_total')->default(0);
            $table->decimal('elapsed_seconds_total', 12, 2)->default(0);

            /**
             * How many runs it took to clear the mission, counted up to and
             * including the one that cleared it. Null until it is cleared,
             * and frozen afterwards: everything flown later is a pilot
             * chasing stars on a mission they have already beaten, and
             * folding that in would make a pilot who kept practising look
             * slower than one who moved on.
             */
            $table->unsignedInteger('attempts_to_clear')->nullable();

            /**
             * The most recent run, so "missions you have flown" can be
             * ordered by recency without going back to the run table. An id
             * rather than a timestamp for the same reason the run index
             * leads with one: two runs in the same second are still ordered.
             */
            $table->unsignedBigInteger('last_run_id')->nullable();

            $table->timestamps();

            /**
             * One row per pilot per mission, enforced rather than assumed.
             * The incremental path writes under the progress row's lock, so
             * a duplicate here would mean the lock was bypassed — which
             * should be a failed insert, not two half-counted histories.
             */
            $table->unique(['user_id', 'challenge_id'], 'pilot_mission_stats_pilot_mission_unique');

            /**
             * The cohort read: every pilot's best on one mission, which the
             * unique key above cannot serve because it leads with the pilot.
             */
            $table->index(['challenge_id', 'best_score'], 'pilot_mission_stats_mission_best_index');
        });
    }
};
