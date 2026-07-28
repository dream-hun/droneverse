<?php

declare(strict_types=1);

use App\Enums\Plan;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Grandfather every account that existed before gating turned on.
     *
     * Decision 2 in docs/pricing-implementation-plan.md: these people signed up
     * for a platform where everything was free, and this migration is the exact
     * moment that stops being true. Taking their access away to sell it back is
     * not a launch, so they keep it permanently via the same `plan_override`
     * column that serves comped, staff and academic accounts.
     *
     * This deliberately runs in the same deployment as the `required_plan`
     * columns. Any gap between the two is a window in which existing users are
     * locked out of content they already had.
     *
     * Only rows with no override are touched, so re-running against a database
     * where someone has since been set to another plan cannot overwrite them.
     */
    public function up(): void
    {
        DB::table('users')
            ->whereNull('plan_override')
            ->update(['plan_override' => Plan::Pro->value]);
    }
};
