<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Keep the dashboard's resume-card lookup index ordered.
     *
     * The dashboard asks for one pilot's newest in-progress mission. The
     * existing unique key begins with `user_id`, but its next column is the
     * mission id, so it cannot filter by state or satisfy the recency sort.
     * This index turns that hot lookup into a short ordered index range even
     * for pilots with years of completed missions.
     */
    public function up(): void
    {
        Schema::table('user_challenge_progress', function (Blueprint $table): void {
            $table->index(
                ['user_id', 'status', 'updated_at'],
                'ucp_user_status_updated_at_index',
            );
        });
    }

    public function down(): void
    {
        Schema::table('user_challenge_progress', function (Blueprint $table): void {
            $table->dropIndex('ucp_user_status_updated_at_index');
        });
    }
};
