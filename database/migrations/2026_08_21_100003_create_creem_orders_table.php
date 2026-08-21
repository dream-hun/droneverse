<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The pilot's receipts, as the billing settings page lists them.
     *
     * One row per completed Creem order, written by `checkout.completed` and
     * amended by `refund.created`. Creem publishes no receipt URL of its own —
     * invoices live behind the customer portal, which is a magic link minted per
     * request — so this table carries the money and the page links to the portal
     * once rather than to a document per row.
     *
     * `amount` is in minor units of `currency`, matching what Creem reports and
     * what config/plans.php quotes.
     */
    public function up(): void
    {
        Schema::create('creem_orders', function (Blueprint $table): void {
            $table->id();
            $table->morphs('billable');
            $table->string('creem_id')->unique();
            $table->string('checkout_id')->nullable();
            $table->string('customer_id')->index();
            $table->string('product_id')->index();
            $table->string('subscription_id')->nullable()->index();
            $table->string('currency');
            $table->integer('amount');
            $table->string('status');
            $table->string('type')->nullable();
            $table->boolean('refunded')->default(false);
            $table->integer('refunded_amount')->nullable();
            $table->timestamp('refunded_at')->nullable();
            $table->timestamp('ordered_at');
            $table->timestamps();
        });
    }
};
