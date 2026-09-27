<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\ChallengeStatus;
use App\Http\Resources\Admin\AdminUserResource;
use App\Models\ChallengeRun;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\Relation;

/**
 * One account as the admin area shows it: who they are, which plan they are
 * on, and what they have flown.
 *
 * What they pay is not here. Kelviq is the only record of it, and the Kelviq
 * dashboard is where staff read and act on it; the account's plan, resolved
 * the same way every page resolves it, is what this page can vouch for.
 */
final readonly class BuildAdminAccount
{
    /** Recent runs on an account's page. */
    private const int RECENT_LIMIT = 10;

    /**
     * @return array{
     *     account: array<string, mixed>,
     *     stats: array{missionsCompleted: int, quizzesPassed: int, photos: int, lastRunAt: string|null},
     *     recentRuns: array<int, array{uuid: string, challengeTitle: string, courseTitle: string, score: int, stars: int, completed: bool, collisions: int, elapsedSeconds: float, flownAt: string}>,
     * }
     */
    public function handle(User $user, User $viewer): array
    {
        $user->load('roles')->loadCount('challengeRuns');

        $runs = $this->recentRuns($user);

        return [
            'account' => AdminUserResource::one($user, $viewer),
            'stats' => $this->stats($user, $runs->first()),
            'recentRuns' => $runs->map(fn (ChallengeRun $run): array => [
                'uuid' => $run->uuid,
                'challengeTitle' => $run->challenge->title ?? __('A retired mission'),
                'courseTitle' => $run->challenge->course->title ?? '',
                'score' => $run->score,
                'stars' => $run->stars,
                'completed' => $run->completed,
                'collisions' => $run->collisions,
                'elapsedSeconds' => $run->elapsed_seconds,
                'flownAt' => $run->created_at?->toIso8601String() ?? '',
            ])->all(),
        ];
    }

    /**
     * @return Collection<int, ChallengeRun>
     */
    private function recentRuns(User $user): Collection
    {
        return ChallengeRun::query()
            ->where('user_id', $user->id)
            ->with(['challenge' => function (Relation $challenge): void {
                $challenge->select(['id', 'course_id', 'title', 'slug']);
                $challenge->with(['course' => function (Relation $course): void {
                    $course->select(['id', 'title', 'slug']);
                }]);
            }])
            ->latest('id')
            ->limit(self::RECENT_LIMIT)
            ->get();
    }

    /**
     * @return array{missionsCompleted: int, quizzesPassed: int, photos: int, lastRunAt: string|null}
     */
    private function stats(User $user, ?ChallengeRun $lastRun): array
    {
        return [
            'missionsCompleted' => $user->challengeProgress()->where('status', ChallengeStatus::Completed)->count(),
            'quizzesPassed' => $user->quizProgress()->whereNotNull('passed_at')->count(),
            'photos' => $user->dronePhotos()->count(),
            'lastRunAt' => $lastRun?->created_at?->toIso8601String(),
        ];
    }
}
