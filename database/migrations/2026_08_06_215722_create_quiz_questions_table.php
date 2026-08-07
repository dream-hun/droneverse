<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One question on a quiz.
     *
     * `type` decides how the answer set is read — see App\Enums\QuizQuestionType.
     * It is stored per question rather than per quiz so a single quiz can mix
     * a straight recall question with one that has two correct answers.
     *
     * `explanation` is the teaching half of the feature and it is nullable
     * because not every question needs one. It travels to the client only
     * after a submission has been graded; App\Http\Resources\QuizDetailResource
     * does not carry it, for the same reason the option key does not.
     *
     * Every question on a quiz is worth the same. A `points` column was
     * considered and left out: weighting only means something once an author
     * has a reason to say one question matters more, and a column nobody sets
     * still has to be read, summed and defended by the grader. The pass mark
     * is a percentage of questions answered correctly, which needs no weights
     * to be fair and no migration to stay honest when a question is added.
     */
    public function up(): void
    {
        Schema::create('quiz_questions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('quiz_id')->constrained()->cascadeOnDelete();
            $table->text('prompt');
            $table->string('type')->default('single');
            $table->text('explanation')->nullable();
            $table->unsignedInteger('order')->default(0);
            $table->timestamps();

            $table->index(['quiz_id', 'order']);
        });
    }
};
