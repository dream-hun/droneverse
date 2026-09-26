<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Which Creem transaction paid for each receipt.
     *
     * A Creem subscription has one order — the checkout's — and every renewal
     * is a further transaction against that same order. Keyed on the order
     * alone, every renewal looked like the checkout arriving again and was
     * dropped, so one row per payment needs the transaction to tell them
     * apart. Nullable because rows written before this column existed are
     * matched to their transaction the next time it is seen; see
     * App\Actions\RecordCreemTransaction.
     */
    public function up(): void
    {
        Schema::table('creem_orders', function (Blueprint $table): void {
            $table->string('transaction_id')->nullable()->unique();
        });
    }
};
