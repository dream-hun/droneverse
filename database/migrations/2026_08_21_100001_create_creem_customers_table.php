<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The mapping between an account here and a customer at Creem.
     *
     * One row per billable, written by the webhook rather than by checkout:
     * Creem creates the customer when the payment succeeds, and until then
     * there is no ID to record. It outlives every subscription the pilot has,
     * which is what makes it — rather than a subscription row — the thing the
     * customer portal link is minted against.
     *
     * `creem_id` is nullable so a row can exist before Creem has named the
     * customer, and unique so two accounts can never claim one.
     */
    public function up(): void
    {
        Schema::create('creem_customers', function (Blueprint $table): void {
            $table->id();
            $table->morphs('billable');
            $table->string('creem_id')->nullable()->unique();
            $table->string('email')->nullable();
            $table->timestamps();

            $table->unique(['billable_id', 'billable_type']);
        });
    }
};
