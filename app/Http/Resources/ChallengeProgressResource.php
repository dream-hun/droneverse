<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Enums\ChallengeStatus;
use App\Models\Challenge;
use App\Models\UserChallengeProgress;

/**
 * The viewer's standing on one mission, as the play page reads it.
 *
 * A pilot opening a mission for the first time has no progress row, so the
 * editor falls back to the challenge's starter code rather than an empty
 * buffer. Mirrors the `ChallengeProgress` type in
 * resources/js/types/simulator.ts.
 */
final class ChallengeProgressResource
{
    /**
     * @return array{status: ChallengeStatus, bestScore: int, stars: int, attempts: int, savedCode: string}
     */
    public static function one(Challenge $challenge, ?UserChallengeProgress $progress): array
    {
        return [
            'status' => $progress->status ?? ChallengeStatus::NotStarted,
            'bestScore' => $progress->best_score ?? 0,
            'stars' => $progress->stars ?? 0,
            'attempts' => $progress->attempts ?? 0,
            'savedCode' => $progress->last_code ?? $challenge->starter_code,
        ];
    }
}
