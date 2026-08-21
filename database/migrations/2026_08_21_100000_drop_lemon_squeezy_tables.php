<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Drop the billing tables the Lemon Squeezy integration owned.
     *
     * Their schema was versioned here rather than in the vendor directory, so
     * unlike the Paddle drop before it there are no rows in the `migrations`
     * table to clean up alongside them — the create migrations stay in place
     * and simply describe tables that a later migration removes.
     *
     * Existing rows are not carried across to the Creem tables. A Lemon Squeezy
     * subscription is billed by Lemon Squeezy; copying its row into a table the
     * Creem webhooks own would produce a subscription that nothing on either
     * side would ever update again, entitling a pilot whose card is no longer
     * being charged. Any live Lemon Squeezy subscriber has to be cancelled
     * there and re-subscribed here, which is a commercial decision and not
     * something a migration can make. `plan_override` on the user is how you
     * hold someone harmless in the meantime.
     */
    public function up(): void
    {
        Schema::dropIfExists('lemon_squeezy_orders');
        Schema::dropIfExists('lemon_squeezy_subscriptions');
        Schema::dropIfExists('lemon_squeezy_customers');
    }
};
