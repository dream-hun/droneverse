<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Indexes for the aggregates behind the dashboard and the leaderboard.
     *
     * Both read progress by joining out to challenges and courses so that
     * retired content stays off the board. Without these the joins fall back
     * to scanning: `challenge_id` has no index of its own (the table's
     * unique key leads with `user_id`, so it cannot serve a lookup by
     * challenge), and the per-course board filters challenges by
     * `course_id`, which is likewise unindexed. Progress rows outgrow every
     * other table here, so this is the pair that decides whether the board
     * stays cheap as pilots accumulate.
     */
    public function up(): void
    {
        Schema::table('user_challenge_progress', function (Blueprint $table): void {
            $table->index('challenge_id', 'ucp_challenge_id_index');
        });

        Schema::table('challenges', function (Blueprint $table): void {
            $table->index(['course_id', 'is_published'], 'challenges_course_id_is_published_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('user_challenge_progress', function (Blueprint $table): void {
            $table->dropIndex('ucp_challenge_id_index');
        });

        Schema::table('challenges', function (Blueprint $table): void {
            $table->dropIndex('challenges_course_id_is_published_index');
        });
    }
};
