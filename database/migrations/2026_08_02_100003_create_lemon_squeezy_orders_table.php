<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Copied from lemonsqueezy/laravel so the schema is versioned here rather
     * than in the vendor directory. This replaces Cashier Paddle's
     * `transactions` table as the billing history the settings page reads.
     *
     * The package's two license-key migrations are deliberately not copied:
     * this application sells subscriptions, not licenses, and the package guards
     * its license handling with `Schema::hasTable`, so their absence is a
     * supported configuration rather than an omission.
     */
    public function up(): void
    {
        Schema::create('lemon_squeezy_orders', function (Blueprint $table): void {
            $table->id();
            $table->morphs('billable');
            $table->string('lemon_squeezy_id')->unique();
            $table->string('customer_id');
            $table->uuid('identifier')->unique();
            $table->string('product_id')->index();
            $table->string('variant_id')->index();
            $table->integer('order_number')->unique();
            $table->string('currency');
            $table->integer('subtotal');
            $table->integer('discount_total');
            $table->integer('tax');
            $table->integer('total');
            $table->string('tax_name')->nullable();
            $table->string('status');
            $table->string('receipt_url')->nullable();
            $table->boolean('refunded');
            $table->timestamp('refunded_at')->nullable();
            $table->timestamp('ordered_at');
            $table->timestamps();
        });
    }
};
