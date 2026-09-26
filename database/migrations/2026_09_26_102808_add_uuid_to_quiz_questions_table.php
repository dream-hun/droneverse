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
     * A public identifier for a quiz question, for the admin editor's URLs.
     *
     * A question has no slug and never needed an address until staff could
     * edit one at a time. The same three steps as users and drone photos:
     * nullable, backfilled in chunks, then closed to null.
     *
     * Options get nothing. They are only ever edited inside their question's
     * form, and the public quiz page already names them by id in its payload.
     */
    public function up(): void
    {
        Schema::table('quiz_questions', function (Blueprint $table): void {
            $table->uuid()->nullable()->after('id')->unique();
        });

        DB::table('quiz_questions')
            ->whereNull('uuid')
            ->select('id')
            ->orderBy('id')
            ->chunkById(500, function ($questions): void {
                foreach ($questions as $question) {
                    DB::table('quiz_questions')
                        ->where('id', $question->id)
                        ->update(['uuid' => (string) Str::uuid7()]);
                }
            });

        Schema::table('quiz_questions', function (Blueprint $table): void {
            $table->uuid()->nullable(false)->change();
        });
    }
};
