<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Keep a payment's times to the microsecond, as Kelviq sends them.
     *
     * `kelviq_updated_at` decides whether a delivery is older than the row it
     * would overwrite, and Kelviq dates its changes to the microsecond. Cut to
     * whole seconds, an order created and paid for within the same second was
     * stored as two descriptions of equal age, so whichever arrived last won:
     * a late `order.created` put a paid order back to PENDING.
     *
     * The other three follow because App\Models\Payment writes every date it
     * holds in the one format, and a whole-second column would round the rest.
     */
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table): void {
            $table->timestamp('paid_at', 6)->nullable()->change();
            $table->timestamp('kelviq_updated_at', 6)->nullable()->change();
            $table->timestamp('created_at', 6)->nullable()->change();
            $table->timestamp('updated_at', 6)->nullable()->change();
        });
    }
};
