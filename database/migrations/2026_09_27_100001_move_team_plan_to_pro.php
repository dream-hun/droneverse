<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Retire the Team tier, which Pro now stands in for.
     *
     * Pro is the only plan on sale, and `team` no longer names a case of
     * App\Enums\Plan. Left in place, a stored `team` would fall through every
     * lookup that reads it: a course requiring Team would read as Starter and
     * open to everybody, and a pilot comped onto Team would lose their upgrade.
     * Both are moved onto Pro instead, which is what Team included.
     */
    public function up(): void
    {
        foreach (['courses', 'challenges', 'quizzes'] as $table) {
            DB::table($table)->where('required_plan', 'team')->update(['required_plan' => 'pro']);
        }

        DB::table('users')->where('plan_override', 'team')->update(['plan_override' => 'pro']);
    }
};
