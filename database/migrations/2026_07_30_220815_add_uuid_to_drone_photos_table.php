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
     * A public identifier for a photo, so its URL stops counting.
     *
     * A photo is the one user-owned resource this application addresses
     * directly, and it was addressed by its auto-increment id. Ownership is
     * enforced by DronePhotoPolicy, so nobody could ever reach a photo that
     * was not theirs — but the id in a pilot's own URL still told them how
     * many photos every pilot before them had taken. That is volume we were
     * handing out for nothing in return.
     *
     * Added nullable so the column can exist before any row has a value,
     * then backfilled, then closed to null. In that order the migration is
     * safe to run against a table that already has rows in it.
     */
    public function up(): void
    {
        Schema::table('drone_photos', function (Blueprint $table): void {
            $table->uuid()->nullable()->after('id')->unique();
        });

        $this->backfill();

        Schema::table('drone_photos', function (Blueprint $table): void {
            $table->uuid()->nullable(false)->change();
        });
    }

    /**
     * Give every photo that predates the column an identifier.
     *
     * Chunked rather than read whole: this runs against however many photos
     * the log has accumulated, and a deployment is not the moment to find
     * out that number does not fit in memory.
     */
    private function backfill(): void
    {
        DB::table('drone_photos')
            ->whereNull('uuid')
            ->select('id')
            ->orderBy('id')
            ->chunkById(500, function ($photos): void {
                foreach ($photos as $photo) {
                    DB::table('drone_photos')
                        ->where('id', $photo->id)
                        ->update(['uuid' => (string) Str::uuid7()]);
                }
            });
    }
};
