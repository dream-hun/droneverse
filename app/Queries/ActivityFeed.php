<?php

declare(strict_types=1);

namespace App\Queries;

use App\Actions\DescribeCreemProduct;
use App\Actions\FormatMoney;
use App\Enums\AdminPermission;
use App\Models\ChallengeRun;
use App\Models\Order;
use App\Models\QuizAttempt;
use App\Models\Subscription;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use InvalidArgumentException;

/**
 * Everything that happened on the platform, newest first, as one stream.
 *
 * There is no events table behind this, and deliberately so. Each thing worth
 * seeing already leaves a row somewhere — an account, a graded run, a quiz
 * attempt, an order, a subscription — and a second copy written alongside it
 * would be a second record of the same fact, free to disagree with the first.
 * The feed is read from the records themselves.
 *
 * Paging a merge of seven tables is done without a union. For page n of size
 * k, the newest n·k + 1 rows of each source are enough to contain the newest
 * n·k + 1 of all of them together, so each source is asked for that many —
 * by primary key, or by an indexed date — and the merge, sort and slice
 * happen here. The cost of a page is bounded by how deep it is, never by how
 * big the tables have grown, and nothing depends on how a particular database
 * spells a union with an ORDER BY in each branch. The page number is capped
 * for the same reason: this is for seeing what is happening, and a history
 * lives on the pages that own it.
 *
 * @phpstan-type Pilot array{uuid: string, name: string, email: string}
 * @phpstan-type Item array{key: string, kind: string, occurredAt: string, pilot: Pilot|null, title: string, meta: string|null}
 */
final readonly class ActivityFeed
{
    public const int PER_PAGE = 25;

    public const int MAX_PAGE = 20;

    public const array PLATFORM_KINDS = ['signup', 'run', 'quiz'];

    public const array FINANCE_KINDS = ['order', 'refund', 'subscription', 'cancellation'];

    public function __construct(
        private FormatMoney $money,
        private DescribeCreemProduct $products,
    ) {}

    /**
     * The sources a member of staff may read.
     *
     * The feed is on the overview, which every member of staff can open, and
     * money is not something every member of staff is trusted with — so the
     * billing sources are left out entirely for anyone without
     * `view_finance`, rather than shown with the amounts blanked.
     *
     * @return array<int, string>
     */
    public function kindsVisibleTo(User $viewer): array
    {
        return $viewer->can(AdminPermission::ViewFinance->value)
            ? [...self::PLATFORM_KINDS, ...self::FINANCE_KINDS]
            : self::PLATFORM_KINDS;
    }

    /**
     * @param  array<int, string>  $kinds  Which sources to read; see kindsVisibleTo().
     * @return array{items: array<int, Item>, hasMore: bool, page: int}
     */
    public function page(array $kinds, int $page = 1, int $perPage = self::PER_PAGE): array
    {
        $page = max(1, min($page, self::MAX_PAGE));
        $window = $page * $perPage + 1;

        $items = collect($kinds)
            ->unique()
            ->flatMap(fn (string $kind): Collection => $this->source($kind, $window))
            ->sort(fn (array $a, array $b): int => [$b['at'], $b['item']['key']] <=> [$a['at'], $a['item']['key']])
            ->values()
            ->slice(($page - 1) * $perPage, $perPage + 1)
            ->map(fn (array $entry): array => $entry['item'])
            ->values();

        return [
            'items' => $items->take($perPage)->all(),
            'hasMore' => $items->count() > $perPage && $page < self::MAX_PAGE,
            'page' => $page,
        ];
    }

    /**
     * @return Collection<int, array{at: int, item: Item}>
     */
    private function source(string $kind, int $limit): Collection
    {
        return match ($kind) {
            'signup' => $this->signups($limit),
            'run' => $this->runs($limit),
            'quiz' => $this->quizzes($limit),
            'order' => $this->orders($limit),
            'refund' => $this->refunds($limit),
            'subscription' => $this->subscriptions($limit),
            'cancellation' => $this->cancellations($limit),
            default => throw new InvalidArgumentException(sprintf('No activity source is called [%s].', $kind)),
        };
    }

    /**
     * @return Collection<int, array{at: int, item: Item}>
     */
    private function signups(int $limit): Collection
    {
        return User::query()
            ->select(['id', 'uuid', 'name', 'email', 'created_at'])
            ->latest('id')
            ->limit($limit)
            ->get()
            ->map(fn (User $user): array => $this->entry(
                'signup',
                $user->id,
                $user->created_at,
                $user,
                'Opened an account',
                null,
            ));
    }

    /**
     * @return Collection<int, array{at: int, item: Item}>
     */
    private function runs(int $limit): Collection
    {
        return ChallengeRun::query()
            ->select(['id', 'user_id', 'challenge_id', 'score', 'stars', 'completed', 'collisions', 'created_at'])
            ->with([
                'user:id,uuid,name,email',
                'challenge:id,course_id,title',
                'challenge.course:id,title',
            ])
            ->latest('id')
            ->limit($limit)
            ->get()
            ->map(fn (ChallengeRun $run): array => $this->entry(
                'run',
                $run->id,
                $run->created_at,
                $run->user,
                sprintf('Flew %s', $run->challenge->title ?? 'a retired mission'),
                sprintf(
                    '%s · %d pts · %d★ · %s',
                    $run->challenge->course->title ?? '',
                    $run->score,
                    $run->stars,
                    $run->completed ? 'cleared' : 'not cleared',
                ),
            ));
    }

    /**
     * @return Collection<int, array{at: int, item: Item}>
     */
    private function quizzes(int $limit): Collection
    {
        return QuizAttempt::query()
            ->select(['id', 'user_id', 'quiz_id', 'score', 'passed', 'created_at'])
            ->with(['user:id,uuid,name,email', 'quiz:id,course_id,title', 'quiz.course:id,title'])
            ->latest('id')
            ->limit($limit)
            ->get()
            ->map(fn (QuizAttempt $attempt): array => $this->entry(
                'quiz',
                $attempt->id,
                $attempt->created_at,
                $attempt->user,
                sprintf('Took %s', $attempt->quiz->title ?? 'a retired quiz'),
                sprintf(
                    '%s · %d%% · %s',
                    $attempt->quiz->course->title ?? '',
                    $attempt->score,
                    $attempt->passed ? 'passed' : 'not passed',
                ),
            ));
    }

    /**
     * @return Collection<int, array{at: int, item: Item}>
     */
    private function orders(int $limit): Collection
    {
        return $this->billed(Order::query())
            ->latest('ordered_at')
            ->latest('id')
            ->limit($limit)
            ->get()
            ->map(fn (Order $order): array => $this->entry(
                'order',
                $order->id,
                $order->ordered_at,
                $this->pilot($order->billable),
                sprintf('Paid %s', $this->money->handle($order->amount, $order->currency)),
                sprintf('%s · %s', $this->products->handle($order->product_id), $order->status),
            ));
    }

    /**
     * @return Collection<int, array{at: int, item: Item}>
     */
    private function refunds(int $limit): Collection
    {
        return $this->billed(Order::query())
            ->whereNotNull('refunded_at')
            ->latest('refunded_at')
            ->latest('id')
            ->limit($limit)
            ->get()
            ->map(fn (Order $order): array => $this->entry(
                'refund',
                $order->id,
                $order->refunded_at,
                $this->pilot($order->billable),
                sprintf('Refunded %s', $this->money->handle($order->refunded_amount ?? $order->amount, $order->currency)),
                $this->products->handle($order->product_id),
            ));
    }

    /**
     * @return Collection<int, array{at: int, item: Item}>
     */
    private function subscriptions(int $limit): Collection
    {
        return $this->billed(Subscription::query())
            ->latest('id')
            ->limit($limit)
            ->get()
            ->map(fn (Subscription $subscription): array => $this->entry(
                'subscription',
                $subscription->id,
                $subscription->created_at,
                $this->pilot($subscription->billable),
                sprintf('Subscribed to %s', $this->products->handle($subscription->product_id)),
                sprintf('Now %s', str_replace('_', ' ', $subscription->status)),
            ));
    }

    /**
     * @return Collection<int, array{at: int, item: Item}>
     */
    private function cancellations(int $limit): Collection
    {
        return $this->billed(Subscription::query())
            ->whereNotNull('canceled_at')
            ->latest('canceled_at')
            ->latest('id')
            ->limit($limit)
            ->get()
            ->map(fn (Subscription $subscription): array => $this->entry(
                'cancellation',
                $subscription->id,
                $subscription->canceled_at,
                $this->pilot($subscription->billable),
                sprintf('Cancelled %s', $this->products->handle($subscription->product_id)),
                $subscription->endsAt() instanceof CarbonInterface
                    ? sprintf('Access ends %s', $subscription->endsAt()->toFormattedDateString())
                    : null,
            ));
    }

    /**
     * Billing rows that belong to a pilot, with that pilot loaded.
     *
     * @template TModel of Order|Subscription
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    private function billed(Builder $query): Builder
    {
        return $query
            ->where('billable_type', (new User)->getMorphClass())
            ->with('billable');
    }

    /**
     * @return Pilot|null
     */
    private function pilot(?Model $billable): ?array
    {
        return $billable instanceof User ? $this->pilotFor($billable) : null;
    }

    /**
     * @return Pilot
     */
    private function pilotFor(User $user): array
    {
        return ['uuid' => $user->uuid, 'name' => $user->name, 'email' => $user->email];
    }

    /**
     * One row of the feed, with the timestamp it sorts by kept beside it.
     *
     * @param  User|Pilot|null  $pilot
     * @return array{at: int, item: Item}
     */
    private function entry(string $kind, int $id, ?CarbonInterface $at, User|array|null $pilot, string $title, ?string $meta): array
    {
        return [
            'at' => $at?->getTimestamp() ?? 0,
            'item' => [
                'key' => sprintf('%s:%d', $kind, $id),
                'kind' => $kind,
                'occurredAt' => $at?->toIso8601String() ?? '',
                'pilot' => $pilot instanceof User ? $this->pilotFor($pilot) : $pilot,
                'title' => $title,
                'meta' => $meta,
            ],
        ];
    }
}
