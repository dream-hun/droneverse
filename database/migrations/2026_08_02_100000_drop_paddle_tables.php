<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Drop the billing tables left behind by laravel/cashier-paddle.
     *
     * The package shipped its migrations from the vendor directory, so removing
     * the dependency left these tables — and their rows in the `migrations`
     * table — with nothing to manage them. Subscription items and transactions
     * both hang off subscriptions and customers, so the drops run children
     * first.
     *
     * Existing rows are not carried across to the Lemon Squeezy tables. A Paddle
     * subscription is billed by Paddle; copying its row into a table the Lemon
     * Squeezy webhooks own would produce a subscription that nothing on either
     * side would ever update again. Any live Paddle subscriber has to be
     * re-subscribed, which is a commercial decision and not something a
     * migration can make.
     */
    public function up(): void
    {
        Schema::dropIfExists('transactions');
        Schema::dropIfExists('subscription_items');
        Schema::dropIfExists('subscriptions');
        Schema::dropIfExists('customers');

        DB::table('migrations')
            ->whereIn('migration', [
                '2019_05_03_000001_create_customers_table',
                '2019_05_03_000002_create_subscriptions_table',
                '2019_05_03_000003_create_subscription_items_table',
                '2019_05_03_000004_create_transactions_table',
            ])
            ->delete();
    }
};
