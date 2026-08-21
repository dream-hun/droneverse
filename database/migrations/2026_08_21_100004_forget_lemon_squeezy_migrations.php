<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Clear the `migrations` rows the Lemon Squeezy schema left behind.
     *
     * Two different sets of migrations created those tables over the project's
     * life, and a given database may have been through either. Both are named
     * below, and clearing both is what makes this reachable from every state a
     * database can be in.
     *
     * Its own migration rather than part of the drop beside it, and that is the
     * whole point of the file. The drop has already run on the databases that
     * were healthy, so anything added there now would never reach them — and
     * they are exactly the ones holding rows that name migration files this
     * commit deletes. Running last means every database gets here, whether it
     * arrived with tables to drop or with nothing but stale bookkeeping.
     *
     * Nothing here touches a table. A row in `migrations` is a record of work
     * already done, and the work is done either way.
     */
    public function up(): void
    {
        DB::table('migrations')
            ->whereIn('migration', [...$this->packageMigrations(), ...$this->versionedMigrations()])
            ->delete();
    }

    /**
     * The names lemonsqueezy/laravel recorded its own migrations under.
     *
     * They ran from the vendor directory on any database that migrated before
     * `LemonSqueezy::ignoreMigrations()` was called. Generic on purpose — they
     * are the package's file names, not ours — which is exactly why a database
     * carrying them looked untouched by Lemon Squeezy to anything searching
     * `migrations` for the name.
     *
     * @return array<int, string>
     */
    private function packageMigrations(): array
    {
        return [
            '2023_01_16_000001_create_customers_table',
            '2023_01_16_000002_create_subscriptions_table',
            '2023_01_16_000003_create_orders_table',
            '2023_01_16_000004_create_license_keys_table',
            '2023_01_16_000005_create_license_key_instances_table',
        ];
    }

    /**
     * The copies this repository versioned, deleted in the same commit as this
     * file.
     *
     * Removed rather than kept, because they could only ever do harm from here.
     * On a database that had been through the package's path above they were a
     * collision that stopped `migrate` dead with "table already exists", every
     * attempt, forever — the tables were there and nothing was left to reconcile
     * the two sets, the migration that would have done it having been deleted in
     * the same commit that introduced these. And on every other database they
     * created three tables that the drop beside this one removes a moment later.
     *
     * @return array<int, string>
     */
    private function versionedMigrations(): array
    {
        return [
            '2026_08_02_100001_create_lemon_squeezy_customers_table',
            '2026_08_02_100002_create_lemon_squeezy_subscriptions_table',
            '2026_08_02_100003_create_lemon_squeezy_orders_table',
        ];
    }
};
