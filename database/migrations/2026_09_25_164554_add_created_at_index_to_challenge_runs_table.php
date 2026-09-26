<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Flights by time window, for the admin overview.
     *
     * Every read of this table until now named a pilot or a mission, and both
     * existing indexes lead with one of those. The overview names neither: it
     * asks how many runs landed in the last day and how many per day over the
     * last fortnight, and without this that is a scan of the fastest-growing
     * table in the schema on every visit.
     *
     * One extra index on an append-only table is a small, fixed cost per
     * insert, which is the trade for making the question cheap to ask often.
     */
    public function up(): void
    {
        Schema::table('challenge_runs', function (Blueprint $table): void {
            $table->index('created_at', 'challenge_runs_created_at_index');
        });
    }
};
