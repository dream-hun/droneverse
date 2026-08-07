<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One selectable answer, and whether it is a correct one.
     *
     * `is_correct` is the answer key. It is a column on a table the client
     * never receives in full — App\Http\Resources\QuizDetailResource ships
     * `id` and `label` and nothing else, and App\Models\QuizOption hides the
     * flag from serialization so an accidental `toArray()` anywhere in the
     * stack cannot leak it either. That is the same belt-and-braces the
     * mission solution gets, and for the same reason: the browser is never
     * trusted with a result, so it must not be handed the means to compute
     * one. A grader that reads this column server-side is the only grader
     * there is.
     *
     * A question may mark more than one option correct; that is what makes it
     * a `multiple` question rather than a `single` one.
     */
    public function up(): void
    {
        Schema::create('quiz_options', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('quiz_question_id')->constrained()->cascadeOnDelete();
            $table->text('label');
            $table->boolean('is_correct')->default(false);
            $table->unsignedInteger('order')->default(0);
            $table->timestamps();

            $table->index(['quiz_question_id', 'order']);
        });
    }
};
