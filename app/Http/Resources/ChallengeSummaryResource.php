<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Enums\ChallengeStatus;
use App\Models\Challenge;
use App\Models\UserChallengeProgress;
use Illuminate\Support\Collection;

/**
 * A challenge row on a course page, merged with the viewer's progress.
 *
 * A guest — or a pilot who has never opened the mission — has no progress
 * row, which is the same thing as not having started, so the defaults here
 * are the single place that decision is made. Mirrors the
 * `ChallengeSummary` type in resources/js/types/simulator.ts.
 */
final class ChallengeSummaryResource
{
    /**
     * @param  Collection<int, Challenge>  $challenges
     * @param  Collection<int, UserChallengeProgress>  $progressByChallenge  keyed by challenge id
     * @return array<int, array{title: string, slug: string, briefing: string, difficulty: string, status: ChallengeStatus, bestScore: int, stars: int}>
     */
    public static function collection(Collection $challenges, Collection $progressByChallenge): array
    {
        return $challenges
            ->map(function (Challenge $challenge) use ($progressByChallenge): array {
                $progress = $progressByChallenge->get($challenge->id);

                return [
                    'title' => $challenge->title,
                    'slug' => $challenge->slug,
                    'briefing' => $challenge->briefing,
                    'difficulty' => $challenge->difficulty,
                    'status' => $progress->status ?? ChallengeStatus::NotStarted,
                    'bestScore' => $progress->best_score ?? 0,
                    'stars' => $progress->stars ?? 0,
                ];
            })
            ->all();
    }
}
