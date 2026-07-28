<?php

declare(strict_types=1);

use App\Enums\Plan;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The tier a piece of catalog content belongs to.
     *
     * A course always states its own tier. A challenge's column is nullable
     * because null means "whatever my course says" — the common case by a wide
     * margin, and the one that keeps a course's missions from drifting apart
     * from it silently. A challenge only stores a value when it deliberately
     * departs from its course, which is how Precision Flight can be a browsable
     * Starter course whose missions are all Pro.
     *
     * Both default to Starter so existing rows stay exactly as reachable as
     * they were before gating landed; the seeder sets the real split.
     */
    public function up(): void
    {
        Schema::table('courses', function (Blueprint $table): void {
            $table->string('required_plan')->default(Plan::Starter->value)->after('difficulty');
        });

        Schema::table('challenges', function (Blueprint $table): void {
            $table->string('required_plan')->nullable()->after('difficulty');
        });
    }
};
