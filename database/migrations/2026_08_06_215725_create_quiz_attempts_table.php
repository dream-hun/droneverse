<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One graded submission, kept forever.
     *
     * Stands to `user_quiz_progress` exactly as `challenge_runs` stands to
     * `user_challenge_progress`, and exists for the same reason: the progress
     * row merges every attempt into a single monotonic best, which is the
     * right shape for the hot path and destroys every question worth asking
     * afterwards. How many tries a quiz actually takes, whether the second
     * attempt is better than the first, which quizzes pilots fail repeatedly
     * — all of those are questions about these rows.
     *
     * Append-only, hence `created_at` alone: a graded submission is a fact
     * about a moment and nothing later revises it.
     *
     * The chosen answers are deliberately not stored. Per-option answer
     * history is a much wider and faster-growing table than this one, it is
     * only worth building once someone is actually asking which distractor
     * pilots fall for, and it carries the same pruning problem `challenge_runs`
     * already owes. The counts here are what a pilot's history reads, and
     * they fit in a narrow row.
     */
    public function up(): void
    {
        Schema::create('quiz_attempts', function (Blueprint $table): void {
            $table->id();
            $table->uuid()->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('quiz_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('score')->default(0);
            $table->unsignedSmallInteger('correct_count')->default(0);
            $table->unsignedSmallInteger('question_count')->default(0);
            $table->boolean('passed')->default(false);
            $table->timestamp('created_at')->nullable();

            /*
             * One pilot's attempt history on one quiz, in submission order.
             * Ordering by `id` rather than `created_at` keeps the sort inside
             * the index and still separates two submissions landing in the
             * same second — the same reasoning as the `challenge_runs` index.
             */
            $table->index(['user_id', 'quiz_id', 'id'], 'quiz_attempts_pilot_quiz_index');

            /*
             * Pass rates and score distributions for one quiz across every
             * pilot. Leads with `quiz_id` because the index above cannot serve
             * a lookup that does not name a pilot.
             */
            $table->index('quiz_id', 'quiz_attempts_quiz_id_index');
        });
    }
};
