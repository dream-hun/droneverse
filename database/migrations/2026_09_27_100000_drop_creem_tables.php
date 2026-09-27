<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Drop the billing tables the Creem integration owned, and forget the
     * migrations that made them.
     *
     * Kelviq replaces Creem, and Kelviq is read rather than mirrored: what a
     * pilot has paid for is asked of its entitlements API, so nothing takes the
     * place of these tables. Creem only ever ran in test mode, so there is no
     * live subscription here to carry across — `plan_override` on the user is
     * how anybody would be held harmless if there were.
     *
     * The migrations that created these tables are deleted in the same commit
     * as this file, so their rows are cleared here too: a row naming a file
     * that no longer exists is a record of work nobody can read. On a fresh
     * database neither the tables nor the rows exist, and this does nothing.
     *
     * Orders and subscriptions name their customer, so the drops run children
     * first.
     */
    public function up(): void
    {
        Schema::dropIfExists('creem_orders');
        Schema::dropIfExists('creem_subscriptions');
        Schema::dropIfExists('creem_customers');

        DB::table('migrations')
            ->whereIn('migration', [
                '2026_08_21_100001_create_creem_customers_table',
                '2026_08_21_100002_create_creem_subscriptions_table',
                '2026_08_21_100003_create_creem_orders_table',
                '2026_09_26_120000_add_transaction_id_to_creem_orders_table',
            ])
            ->delete();
    }
};
