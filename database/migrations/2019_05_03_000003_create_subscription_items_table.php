<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Cashier Paddle's subscription item table, published from the package.
     *
     * `price_id` is the column ResolvePlanForUser maps back onto a plan via
     * config/plans.php.
     */
    public function up(): void
    {
        Schema::create('subscription_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('subscription_id');
            $table->string('product_id');
            $table->string('price_id');
            $table->string('status');
            $table->integer('quantity');
            $table->timestamps();

            $table->unique(['subscription_id', 'price_id']);
        });
    }
};
