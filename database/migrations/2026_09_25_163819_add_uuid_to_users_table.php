<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * A public identifier for a pilot's account.
     *
     * Nothing addressed a user by URL until the admin area, which manages them
     * one at a time — and an auto-increment id there is a running count of
     * every account ever opened, sitting in the address bar of anyone with
     * access to a single one of them.
     *
     * The same three steps drone_photos took: added nullable so the column
     * can exist before any row has a value, backfilled, then closed to null.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->uuid()->nullable()->after('id')->unique();
        });

        $this->backfill();

        Schema::table('users', function (Blueprint $table): void {
            $table->uuid()->nullable(false)->change();
        });
    }

    /**
     * Chunked rather than read whole, for the reason the drone photo backfill
     * gives: a deployment is not the moment to learn the table does not fit
     * in memory.
     */
    private function backfill(): void
    {
        DB::table('users')
            ->whereNull('uuid')
            ->select('id')
            ->orderBy('id')
            ->chunkById(500, function ($users): void {
                foreach ($users as $user) {
                    DB::table('users')
                        ->where('id', $user->id)
                        ->update(['uuid' => (string) Str::uuid7()]);
                }
            });
    }
};
