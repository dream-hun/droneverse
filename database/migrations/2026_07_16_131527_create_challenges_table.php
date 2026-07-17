<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('challenges', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('course_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->string('slug');
            $table->text('briefing');
            $table->unsignedInteger('order')->default(0);
            $table->string('difficulty');
            $table->text('starter_code');
            $table->json('environment');
            $table->json('success_criteria');
            $table->unsignedInteger('max_score')->default(100);
            $table->boolean('is_published')->default(false);
            $table->timestamps();

            $table->unique(['course_id', 'slug']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('challenges');
    }
};
