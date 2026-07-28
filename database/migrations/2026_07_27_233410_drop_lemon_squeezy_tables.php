<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Drop the billing tables left behind by lemonsqueezy/laravel.
     *
     * The package shipped its migrations from the vendor directory, so removing
     * the dependency left these tables — and their rows in the `migrations`
     * table — with nothing to manage them. All five were empty; nothing in the
     * application ever read or wrote them. License keys carry a foreign key
     * onto orders, so the drops run children first.
     */
    public function up(): void
    {
        Schema::dropIfExists('lemon_squeezy_license_key_instances');
        Schema::dropIfExists('lemon_squeezy_license_keys');
        Schema::dropIfExists('lemon_squeezy_orders');
        Schema::dropIfExists('lemon_squeezy_subscriptions');
        Schema::dropIfExists('lemon_squeezy_customers');

        DB::table('migrations')
            ->whereIn('migration', [
                '2023_01_16_000001_create_customers_table',
                '2023_01_16_000002_create_subscriptions_table',
                '2023_01_16_000003_create_orders_table',
                '2023_01_16_000004_create_license_keys_table',
                '2023_01_16_000005_create_license_key_instances_table',
            ])
            ->delete();
    }
};
