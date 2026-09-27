<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\ChallengeStatus;
use App\Http\Resources\Admin\AdminOrderResource;
use App\Http\Resources\Admin\AdminSubscriptionResource;
use App\Http\Resources\Admin\AdminUserResource;
use App\Models\ChallengeRun;
use App\Models\Order;
use App\Models\Subscription;
use App\Models\User;
use App\Queries\DefaultSubscription;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\Relation;

/**
 * One account as the admin area shows it: who they are, what they pay for,
 * and what they have flown.
 *
 * The money is shaped for the viewer. Subscriptions and orders are null
 * rather than empty for staff without `view_finance`, so the page can tell
 * "nothing bought" from "not yours to see".
 */
final readonly class BuildAdminAccount
{
    /** Recent runs, orders and subscriptions on an account's page. */
    private const int RECENT_LIMIT = 10;

    public function __construct(private DefaultSubscription $subscriptions)
    {
        //
    }

    /**
     * @return array{
     *     account: array<string, mixed>,
     *     stats: array{missionsCompleted: int, quizzesPassed: int, photos: int, lastRunAt: string|null},
     *     recentRuns: array<int, array{uuid: string, challengeTitle: string, courseTitle: string, score: int, stars: int, completed: bool, collisions: int, elapsedSeconds: float, flownAt: string}>,
     *     subscriptions: array<int, array<string, mixed>>|null,
     *     canCancelSubscription: bool,
     *     orders: array<int, array<string, mixed>>|null,
     * }
     */
    public function handle(User $user, User $viewer): array
    {
        $user->load(['roles', 'subscriptions'])->loadCount('challengeRuns');

        $runs = $this->recentRuns($user);
        $seesFinance = $viewer->can('view_finance');

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
            'subscriptions' => $seesFinance ? AdminSubscriptionResource::collection($this->recentSubscriptions($user)) : null,
            /*
             * Offered only where cancelling would do something: a subscription
             * still billing, on an account this viewer may edit, to somebody
             * trusted with the money.
             */
            'canCancelSubscription' => $seesFinance
                && $viewer->can('update', $user)
                && $this->subscriptions->isSwitchable($this->subscriptions->for($user)),
            'orders' => $seesFinance ? AdminOrderResource::collection($this->recentOrders($user)) : null,
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

    /**
     * @return Collection<int, Subscription>
     */
    private function recentSubscriptions(User $user): Collection
    {
        return Subscription::query()
            ->whereMorphedTo('billable', $user)
            ->with('billable')
            ->latest('id')
            ->limit(self::RECENT_LIMIT)
            ->get();
    }

    /**
     * @return Collection<int, Order>
     */
    private function recentOrders(User $user): Collection
    {
        return Order::query()
            ->whereMorphedTo('billable', $user)
            ->with('billable')
            ->latest('ordered_at')
            ->latest('id')
            ->limit(self::RECENT_LIMIT)
            ->get();
    }
}
