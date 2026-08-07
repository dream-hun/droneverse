<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A knowledge check attached to a course.
     *
     * Catalog content, authored and read-mostly, sitting beside `challenges`
     * rather than under them: a mission asks whether a pilot can make the
     * drone do something, a quiz asks whether they understood why. Both hang
     * off the course because that is the unit a pilot finishes.
     *
     * `required_plan` is nullable and means the same thing it means on
     * `challenges` — inherit the course's tier. Setting it is the deliberate
     * exception, and it is what lets a Starter course carry a Pro quiz
     * without the course itself moving tier. Entitlement still points into
     * catalog and never out: nothing here knows what a Plan is beyond the
     * string it stores.
     *
     * `pass_percentage` lives on the row rather than in config because a
     * harder quiz is allowed to demand more, and because changing the bar for
     * one quiz must not silently re-grade every other. It is a percentage, not
     * a raw score, so adding a question to a published quiz does not move the
     * bar underneath the pilots who already passed it.
     *
     * The `(course_id, slug)` unique key is the same one `challenges` carries:
     * slugs are only ever resolved within a course, so two courses may both
     * have a `final-exam` without collision.
     */
    public function up(): void
    {
        Schema::create('quizzes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('course_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->string('slug');
            $table->text('description');
            $table->unsignedInteger('order')->default(0);
            $table->string('required_plan')->nullable();
            $table->unsignedTinyInteger('pass_percentage')->default(70);
            $table->boolean('is_published')->default(false);
            $table->timestamps();

            $table->unique(['course_id', 'slug']);
        });
    }
};
