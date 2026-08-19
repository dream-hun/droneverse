<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * What one pilot's progress in one course adds up to.
     *
     * The leaderboard ranks pilots by summing `user_challenge_progress`,
     * which is already one row per pilot per mission — so unlike the
     * analytics rollup this is not about a table that grows without bound.
     * It is about the width of the group-by. Ranking the overall board
     * grouped every progress row in the schema, and a per-course board
     * grouped a slice of them, so both got more expensive with every mission
     * added to the catalogue and every pilot who flew one.
     *
     * Collapsing to one row per pilot per course divides the ranking scan by
     * the number of missions in a course, and it turns a *per-course* board
     * from a grouping aggregate into an ordered read of a single table.
     *
     * Recomputed, never incremented. A run's effect on a pilot's standing is
     * not a delta — best score and stars are monotonic merges, and a run
     * that beat nothing changes nothing — so the row is rebuilt from that
     * pilot's progress in that course whenever one of those rows moves. It
     * is a handful of rows per rebuild and it cannot drift.
     *
     * The one thing folded in here rather than left to the read is
     * `challenges.is_published`: a course's totals are summed over its
     * published missions only. That is why {@see App\Observers\ChallengeObserver}
     * exists — publishing or retiring a mission, or moving one between
     * courses, has to rebuild the affected courses. `courses.is_published`
     * stays a live join on the read side, so pulling a whole course still
     * takes effect immediately with nothing to rebuild.
     */
    public function up(): void
    {
        Schema::create('pilot_course_totals', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('course_id')->constrained()->cascadeOnDelete();

            /** Summed best scores — the board's points column. */
            $table->unsignedBigInteger('points')->default(0);
            $table->unsignedInteger('stars')->default(0);
            $table->unsignedInteger('completed')->default(0);

            /**
             * When this pilot last completed a mission in the course, which
             * breaks ties on the board in favour of whoever got there first.
             */
            $table->timestamp('finished_at')->nullable();

            $table->timestamps();

            /**
             * A row exists only while the pilot has progress on a *published*
             * mission in the course. The board is a record of simulator time,
             * so a pilot whose only flights were on retired content drops off
             * it rather than appearing on nil points — which means the
             * recompute deletes as well as writes.
             */
            $table->unique(['user_id', 'course_id'], 'pilot_course_totals_pilot_course_unique');

            /**
             * A per-course board, read straight off the index in board order.
             * The overall board still groups, but over this table rather than
             * over every progress row.
             */
            $table->index(['course_id', 'points'], 'pilot_course_totals_course_points_index');
        });
    }
};
