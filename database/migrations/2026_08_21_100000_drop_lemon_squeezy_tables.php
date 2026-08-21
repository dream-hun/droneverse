<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Drop the billing tables the Lemon Squeezy integration owned, however they
     * came to exist.
     *
     * Two different sets of migrations created these tables over the project's
     * life, and a given database may have been through either path — which is
     * the whole reason this migration is shaped the way it is.
     *
     * The package shipped its own migrations from the vendor directory, and
     * they ran before anything called `LemonSqueezy::ignoreMigrations()`. They
     * are recorded under the names in `packageMigrations()` below, which say
     * nothing about Lemon Squeezy at all — `create_customers_table` and so on —
     * so nothing looking for the string ever found them. They also created two
     * license-key tables this application never used.
     *
     * The repository later versioned its own copies of three of them, under the
     * names in `versionedMigrations()`. On a database that had already been
     * through the package's path those copies could never run: the tables were
     * already there, and `migrate` failed on the first one with "table already
     * exists" every time it was attempted. The migration that would have
     * cleaned up after the package was deleted in the same commit that added
     * the copies, so nothing was left to reconcile the two.
     *
     * So every table either set could have made is dropped here, whichever set
     * made it, and a database that never had any of them is untouched. The rows
     * both sets left in `migrations` are cleared by the migration that follows
     * the Creem tables rather than by this one — this migration has already run
     * on some databases, and anything added to it now would never reach them.
     *
     * License keys carry a foreign key onto orders, so the drops run children
     * first.
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
        Schema::dropIfExists('lemon_squeezy_license_key_instances');
        Schema::dropIfExists('lemon_squeezy_license_keys');
        Schema::dropIfExists('lemon_squeezy_orders');
        Schema::dropIfExists('lemon_squeezy_subscriptions');
        Schema::dropIfExists('lemon_squeezy_customers');
    }
};
