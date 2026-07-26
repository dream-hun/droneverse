<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Challenge;

/**
 * Everything the simulator needs to fly a mission.
 *
 * Note what is absent: `solution_code` never travels in this shape. The
 * reference solution ships only through {@see ChallengeSolutionResource},
 * which decides whether the pilot has earned it. Mirrors the
 * `ChallengeDetail` type in resources/js/types/simulator.ts.
 */
final class ChallengeDetailResource
{
    /**
     * @return array{title: string, slug: string, briefing: string, difficulty: string, environment: array<string, mixed>, successCriteria: array<string, mixed>, maxScore: int, starterCode: string}
     */
    public static function one(Challenge $challenge): array
    {
        return [
            'title' => $challenge->title,
            'slug' => $challenge->slug,
            'briefing' => $challenge->briefing,
            'difficulty' => $challenge->difficulty,
            'environment' => $challenge->environment,
            'successCriteria' => $challenge->success_criteria,
            'maxScore' => $challenge->max_score,
            'starterCode' => $challenge->starter_code,
        ];
    }
}
