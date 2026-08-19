<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A pilot's standing on one quiz — the hot row.
     *
     * The exact counterpart of `user_challenge_progress`, and monotonic for
     * the same reason: `best_score` only ever rises and `passed_at` is set
     * once and never cleared, so retaking a quiz can never cost a pilot a
     * pass they have already earned. Retakes are unlimited, which is what
     * makes that guarantee necessary rather than merely kind — a pilot
     * reviewing the questions after passing must not be punished for it.
     *
     * `best_score` is a percentage, matching `quizzes.pass_percentage`, so
     * the two are comparable without knowing how many questions the quiz had
     * when the score was set.
     *
     * The unique key on `(user_id, quiz_id)` is what makes the
     * create-or-select-then-lock flow in App\Actions\RecordQuizAttempt safe:
     * concurrent first submissions converge on this row before serializing
     * their progress merge.
     */
    public function up(): void
    {
        Schema::create('user_quiz_progress', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('quiz_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('best_score')->default(0);
            $table->unsignedInteger('attempts')->default(0);
            $table->timestamp('passed_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'quiz_id']);
        });
    }
};
