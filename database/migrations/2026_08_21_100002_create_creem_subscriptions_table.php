<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A local mirror of one Creem subscription, kept in step by the webhook.
     *
     * `product_id` on the row itself is what answers "which plan is this pilot
     * entitled to?" without a join and without a call to Creem — a Creem
     * product carries its own price and billing period, so one product is one
     * purchasable price and there is no separate price object to look through.
     * App\Actions\ResolvePlanForUser reads it directly.
     *
     * The dates are stored as Creem reports them rather than reduced to a
     * single "ends" column, because they answer different questions and only
     * one of them is ever certain. `renews_at` is when the card is charged
     * next; `current_period_end_at` is when access lapses if it is not;
     * `canceled_at` is when somebody said stop. App\Models\Subscription derives
     * the one date a page actually shows from the three of them.
     *
     * `units` exists for Phase 7: Creem bills seats as a unit count against one
     * product rather than as a second subscription, so a classroom growing by a
     * student is an update to this column and not a new row.
     */
    public function up(): void
    {
        Schema::create('creem_subscriptions', function (Blueprint $table): void {
            $table->id();
            $table->morphs('billable');
            $table->string('type');
            $table->string('creem_id')->unique();
            $table->string('customer_id')->index();
            $table->string('product_id')->index();
            $table->string('status');
            $table->unsignedInteger('units')->default(1);
            $table->timestamp('trial_ends_at')->nullable();
            $table->timestamp('renews_at')->nullable();
            $table->timestamp('current_period_start_at')->nullable();
            $table->timestamp('current_period_end_at')->nullable();
            $table->timestamp('canceled_at')->nullable();
            $table->timestamps();
        });
    }
};
