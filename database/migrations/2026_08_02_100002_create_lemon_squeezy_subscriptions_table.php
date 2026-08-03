<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Copied from lemonsqueezy/laravel so the schema is versioned here rather
     * than in the vendor directory.
     *
     * Note `variant_id` on the row itself: unlike Cashier Paddle, there is no
     * subscription-items table, so the question "which price is this user
     * subscribed to?" is one column and no join. App\Actions\ResolvePlanForUser
     * reads it directly.
     */
    public function up(): void
    {
        Schema::create('lemon_squeezy_subscriptions', function (Blueprint $table): void {
            $table->id();
            $table->morphs('billable');
            $table->string('type');
            $table->string('lemon_squeezy_id')->unique();
            $table->string('status');
            $table->string('product_id');
            $table->string('variant_id');
            $table->string('card_brand')->nullable();
            $table->string('card_last_four')->nullable();
            $table->string('pause_mode')->nullable();
            $table->timestamp('pause_resumes_at')->nullable();
            $table->timestamp('trial_ends_at')->nullable();
            $table->timestamp('renews_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->timestamps();
        });
    }
};
