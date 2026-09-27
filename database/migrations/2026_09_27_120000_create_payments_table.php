<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One row per Kelviq order: every checkout, lifetime purchase and renewal
     * that took money, as Kelviq last described it.
     *
     * A ledger, not an entitlement store. What a pilot may use is still asked
     * of Kelviq's entitlements API on every page, as Kelviq recommends; this
     * is the record of what was paid, so it can be queried, reported on and
     * joined to the rest of the schema without a round trip to Kelviq's
     * dashboard. It is written by the `order.*` webhooks and by
     * `kelviq:sync-payments`, keyed on Kelviq's order id so either can arrive
     * first, or twice.
     *
     * The pilot's row is nulled rather than cascaded: an account can be
     * closed, and the payments it made still happened. `kelviq_customer_id`
     * keeps who they were — the pilot's uuid, as Kelviq knows them.
     *
     * Money is stored in minor units alongside its currency, exactly as
     * Kelviq sends it, so nothing is rounded on the way in.
     *
     * `kelviq_updated_at` is when Kelviq's copy last changed, as far as this
     * row knows. Webhooks can arrive out of order, and an older description
     * of an order never overwrites a newer one.
     */
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table): void {
            $table->id();
            $table->string('kelviq_order_id')->unique();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('kelviq_customer_id')->index();
            $table->string('kelviq_subscription_id')->nullable();
            $table->string('status');
            $table->string('billing_type')->nullable();
            $table->boolean('is_renewal')->nullable();
            $table->string('plan_identifier')->nullable();
            $table->unsignedBigInteger('amount_units')->nullable();
            $table->char('currency', 3)->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('kelviq_updated_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'paid_at']);
        });
    }
};
