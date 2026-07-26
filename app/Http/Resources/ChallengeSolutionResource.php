<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Challenge;
use App\Models\UserChallengeProgress;

/**
 * The reference-solution panel's state.
 *
 * This is the one place the solution source is allowed to leave the server,
 * and it only does so once {@see Challenge::solutionUnlockedBy()} says the
 * pilot has earned it — a locked panel is sent `null`, so the source never
 * reaches the browser to be read out of the page payload. Mirrors the
 * `ChallengeSolution` type in resources/js/types/simulator.ts.
 */
final class ChallengeSolutionResource
{
    /**
     * @return array{exists: bool, unlocked: bool, code: string|null, attemptsRequired: int}
     */
    public static function one(Challenge $challenge, ?UserChallengeProgress $progress): array
    {
        $unlocked = $challenge->solutionUnlockedBy($progress);

        return [
            'exists' => $challenge->solution_code !== null,
            'unlocked' => $unlocked,
            'code' => $unlocked ? $challenge->solution_code : null,
            'attemptsRequired' => Challenge::ATTEMPTS_BEFORE_SOLUTION,
        ];
    }
}
