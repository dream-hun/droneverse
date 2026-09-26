<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\Challenge;
use App\Models\DronePhoto;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Remove a mission, and every run, attempt and photo taken on it.
 *
 * Deleted through the model so App\Observers\ChallengeObserver rebuilds the
 * course's totals and retires its board. The photo files are the one thing no
 * cascade reaches, so they go last, after the rows.
 */
final readonly class DeleteChallenge
{
    /**
     * @throws Throwable
     */
    public function handle(Challenge $challenge): void
    {
        $paths = DronePhoto::query()->where('challenge_id', $challenge->id)->pluck('path')->all();

        DB::transaction(fn (): ?bool => $challenge->delete());

        if ($paths !== []) {
            DronePhoto::disk()->delete($paths);
        }
    }
}
