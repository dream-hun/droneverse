<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One row per graded run, kept forever.
     *
     * `user_challenge_progress` merges every attempt into a single monotonic
     * row: best score and stars only rise, and the attempt counter is the
     * only trace a run leaves behind. That is the right shape for the hot
     * path and for the leaderboard, and it destroys every question analytics
     * exists to answer — how a score moved between the first attempt and the
     * tenth, how many tries a mission actually takes, whether the collisions
     * are coming down. Those need the runs themselves, so this table keeps
     * them.
     *
     * Append-only, hence `created_at` alone: a graded run is a fact about a
     * moment and nothing later revises it. Anything that wants to correct one
     * writes another.
     *
     * The pilot's code is deliberately not stored per run. It is tens of
     * kilobytes, it is already kept once on the progress row as the editor's
     * draft buffer, and this is the fastest-growing table in the schema — the
     * numbers a run is worth are what analytics reads, and they fit in a
     * narrow row.
     */
    public function up(): void
    {
        Schema::create('challenge_runs', function (Blueprint $table): void {
            $table->id();
            $table->uuid()->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('challenge_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('score')->default(0);
            $table->unsignedTinyInteger('stars')->default(0);
            $table->boolean('completed')->default(false);
            $table->unsignedSmallInteger('objectives_hit')->default(0);
            $table->unsignedSmallInteger('objectives_total')->default(0);
            $table->unsignedInteger('collisions')->default(0);
            $table->decimal('elapsed_seconds', 8, 2)->default(0);
            $table->boolean('landed')->default(false);
            $table->boolean('timed_out')->default(false);
            $table->timestamp('created_at')->nullable();

            /*
             * The attempt curve for one pilot on one mission, in the order
             * the runs were flown. Ordering by `id` rather than `created_at`
             * is what puts the sort inside the index: two runs submitted in
             * the same second are still ordered, and the read never leaves
             * the index to find out which came first.
             */
            $table->index(['user_id', 'challenge_id', 'id'], 'challenge_runs_pilot_mission_index');

            /*
             * The cohort aggregates — how one pilot's best compares with
             * every pilot's on the same mission. Leads with `challenge_id`
             * because the index above cannot serve a lookup that does not
             * name a pilot.
             */
            $table->index('challenge_id', 'challenge_runs_challenge_id_index');
        });
    }
};
