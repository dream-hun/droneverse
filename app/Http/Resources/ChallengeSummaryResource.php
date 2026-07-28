<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Enums\ChallengeStatus;
use App\Enums\Plan;
use App\Models\Challenge;
use App\Models\Course;
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
     * Rows carry `locked` so the page can render a mission the viewer cannot
     * fly as an upgrade prompt rather than a dead link. The briefing is still
     * sent: what the mission asks of you is the advertisement, and it is not
     * the thing being sold. The starter code, solution and environment are not
     * on this shape at all, so nothing withheld leaks through the lock.
     *
     * @param  Collection<int, Challenge>  $challenges
     * @param  Collection<int, UserChallengeProgress>  $progressByChallenge  keyed by challenge id
     * @return array<int, array{title: string, slug: string, briefing: string, difficulty: string, requiredPlan: string, locked: bool, status: ChallengeStatus, bestScore: int, stars: int}>
     */
    public static function collection(
        Collection $challenges,
        Collection $progressByChallenge,
        Course $course,
        Plan $viewerPlan,
    ): array {
        return $challenges
            ->map(function (Challenge $challenge) use ($progressByChallenge, $course, $viewerPlan): array {
                $progress = $progressByChallenge->get($challenge->id);
                $requiredPlan = $challenge->requiredPlanIn($course);

                return [
                    'title' => $challenge->title,
                    'slug' => $challenge->slug,
                    'briefing' => $challenge->briefing,
                    'difficulty' => $challenge->difficulty,
                    'requiredPlan' => $requiredPlan->value,
                    'locked' => ! $viewerPlan->covers($requiredPlan),
                    'status' => $progress->status ?? ChallengeStatus::NotStarted,
                    'bestScore' => $progress->best_score ?? 0,
                    'stars' => $progress->stars ?? 0,
                ];
            })
            ->all();
    }
}
