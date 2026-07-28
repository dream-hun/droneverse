<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The column ResolvePlanForUser consults ahead of Paddle.
     *
     * `plan_override` is the comp / staff / academic-discount mechanism: a plan
     * value set by hand that outranks every other resolution branch. It is also
     * where pre-launch accounts get backfilled to `pro` when Phase 2 turns
     * gating on.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('plan_override')->nullable()->after('password');
        });
    }
};
