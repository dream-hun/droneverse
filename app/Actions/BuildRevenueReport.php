<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\Plan;
use App\Enums\SubscriptionStatus;
use App\Models\Order;
use App\Models\Subscription;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * The money, as Creem has reported it to this application.
 *
 * Read-only by design. Every figure here comes from the local mirror of
 * Creem's records — the orders `checkout.completed` wrote and the
 * subscriptions the webhooks keep current — and nothing on the finance pages
 * writes to either. Creem is the merchant of record and the only place a
 * charge or a refund can actually happen; a figure edited here would be a
 * figure that disagrees with the bank.
 *
 * Revenue is net of refunds and in one currency: the one config/plans.php
 * prices in. Orders in any other currency are counted and reported beside the
 * totals rather than summed into them, because adding euros to dollars
 * produces a number that is not an amount of anything.
 *
 * Monthly recurring revenue is an estimate, and labelled as one. It prices
 * each paying subscription — entitled and past its trial — at the list amount
 * config/plans.php quotes for the product it sells, with yearly plans spread
 * over twelve months. Creem decides what is actually charged — a discount
 * code, a launch price bought last year — so this is the run rate the price
 * list implies, not a ledger.
 */
final readonly class BuildRevenueReport
{
    /** Months of history the revenue chart spans, this month included. */
    private const int CHART_MONTHS = 12;

    public function __construct(private FormatMoney $money) {}

    /**
     * @return array{
     *     currency: string,
     *     totals: array{thisMonth: array{net: string, refunded: string, orders: int}, last30Days: array{net: string, refunded: string, orders: int}, allTime: array{net: string, refunded: string, orders: int}},
     *     otherCurrencyOrders: int,
     *     monthly: array<int, array{month: string, net: int, formatted: string, orders: int}>,
     *     subscriptions: array{entitled: int, mrr: string, cancelledLast30Days: int, byStatus: array<int, array{status: string, count: int, entitles: bool}>, byPlan: array<int, array{plan: string, count: int}>},
     * }
     */
    public function handle(): array
    {
        $now = CarbonImmutable::now();
        $currency = mb_strtoupper(config()->string('plans.currency'));

        return [
            'currency' => $currency,
            'totals' => [
                'thisMonth' => $this->total($currency, $now->startOfMonth()),
                'last30Days' => $this->total($currency, $now->subDays(30)),
                'allTime' => $this->total($currency, null),
            ],
            'otherCurrencyOrders' => Order::query()->whereRaw('upper(currency) != ?', [$currency])->count(),
            'monthly' => $this->monthly($currency, $now),
            'subscriptions' => $this->subscriptions($currency, $now),
        ];
    }

    /**
     * @return array{net: string, refunded: string, orders: int}
     */
    private function total(string $currency, ?CarbonImmutable $since): array
    {
        $row = fluent($this->orders($currency)
            ->when($since instanceof CarbonImmutable, fn (Builder $orders): Builder => $orders->where('ordered_at', '>=', $since))
            ->selectRaw(
                'count(*) as orders, '
                .'coalesce(sum(amount), 0) as gross, '
                .'coalesce(sum(case when refunded = ? then coalesce(refunded_amount, amount) else 0 end), 0) as refunded',
                [true],
            )
            ->first());

        return [
            'net' => $this->money->handle($row->integer('gross') - $row->integer('refunded'), $currency),
            'refunded' => $this->money->handle($row->integer('refunded'), $currency),
            'orders' => $row->integer('orders'),
        ];
    }

    /**
     * Net revenue per calendar month, oldest first, with empty months as zero.
     *
     * @return array<int, array{month: string, net: int, formatted: string, orders: int}>
     */
    private function monthly(string $currency, CarbonImmutable $now): array
    {
        $since = $now->subMonths(self::CHART_MONTHS - 1)->startOfMonth();
        $month = $this->monthExpression();

        $rows = $this->orders($currency)
            ->where('ordered_at', '>=', $since)
            ->groupByRaw($month)
            ->selectRaw(
                $month.' as month, '
                .'count(*) as orders, '
                .'coalesce(sum(amount), 0) - coalesce(sum(case when refunded = ? then coalesce(refunded_amount, amount) else 0 end), 0) as net',
                [true],
            )
            ->get()
            ->keyBy(fn (stdClass $row): string => (string) fluent($row)->string('month'));

        $months = [];

        for ($cursor = $since; $cursor->lte($now); $cursor = $cursor->addMonth()) {
            $key = $cursor->format('Y-m');
            $row = fluent($rows->get($key) ?? []);
            $net = $row->integer('net');

            $months[] = [
                'month' => $key,
                'net' => $net,
                'formatted' => $this->money->handle($net, $currency, 0),
                'orders' => $row->integer('orders'),
            ];
        }

        return $months;
    }

    /**
     * @return array{entitled: int, mrr: string, cancelledLast30Days: int, byStatus: array<int, array{status: string, count: int, entitles: bool}>, byPlan: array<int, array{plan: string, count: int}>}
     */
    private function subscriptions(string $currency, CarbonImmutable $now): array
    {
        $byStatus = Subscription::query()
            ->toBase()
            ->groupBy('status')
            ->selectRaw('status, count(*) as total')
            ->orderByDesc('total')
            ->get()
            ->map(function (stdClass $record): array {
                $row = fluent($record);
                $status = (string) $row->string('status');

                return [
                    'status' => $status,
                    'count' => $row->integer('total'),
                    'entitles' => SubscriptionStatus::tryFrom($status)?->entitles() === true,
                ];
            })
            ->values()
            ->all();

        /*
         * Entitlement is App\Models\Subscription::valid()'s answer and it
         * reads a date as well as a status, so the candidates are narrowed in
         * SQL by the statuses that can entitle and settled per row by the
         * model — one definition of "paying", not two.
         */
        $entitled = Subscription::query()
            ->select(['id', 'product_id', 'status', 'units', 'current_period_end_at'])
            ->whereIn('status', array_map(
                static fn (SubscriptionStatus $status): string => $status->value,
                array_filter(SubscriptionStatus::cases(), static fn (SubscriptionStatus $status): bool => $status->entitles()),
            ))
            ->get()
            ->filter(fn (Subscription $subscription): bool => $subscription->valid());

        // A trial entitles and has not paid yet, so it counts as a
        // subscriber and not as revenue.
        $mrr = $entitled
            ->reject(fn (Subscription $subscription): bool => $subscription->onTrial())
            ->sum(fn (Subscription $subscription): int => $this->monthlyAmount($subscription));

        $byPlan = $entitled
            ->groupBy(fn (Subscription $subscription): string => Plan::fromPriceId($subscription->product_id)?->label() ?? 'Unrecognised product')
            ->map(fn (Collection $subscriptions, string $plan): array => ['plan' => $plan, 'count' => $subscriptions->count()])
            ->sortByDesc('count')
            ->values()
            ->all();

        return [
            'entitled' => $entitled->count(),
            'mrr' => $this->money->handle($mrr, $currency),
            'cancelledLast30Days' => Subscription::query()->where('canceled_at', '>=', $now->subDays(30))->count(),
            'byStatus' => $byStatus,
            'byPlan' => $byPlan,
        ];
    }

    /**
     * What one subscription is worth per month at list price, in minor units.
     *
     * Yearly and seat variants are recognised by name; a product the price
     * list does not describe contributes nothing rather than a guess.
     */
    private function monthlyAmount(Subscription $subscription): int
    {
        $plan = Plan::fromPriceId($subscription->product_id);
        $variant = $plan?->variantFor($subscription->product_id);

        if (! $plan instanceof Plan || $variant === null) {
            return 0;
        }

        $amount = ($plan->amount($variant) ?? 0) * max(1, $subscription->units);

        return str_contains($variant, 'yearly') ? intdiv($amount, 12) : $amount;
    }

    /**
     * Orders in the report's currency, as a base query to aggregate over.
     */
    private function orders(string $currency): Builder
    {
        return Order::query()->toBase()->whereRaw('upper(currency) = ?', [$currency]);
    }

    /**
     * The calendar month of an order, as `YYYY-MM`.
     *
     * The one place the drivers disagree. Both branches are literals, for the
     * reason App\Actions\RebuildRollups gives: this is concatenated into
     * `selectRaw()`, which only accepts a literal-string.
     *
     * @return literal-string
     */
    private function monthExpression(): string
    {
        return match (DB::connection()->getDriverName()) {
            'mysql', 'mariadb' => "date_format(ordered_at, '%Y-%m')",
            'pgsql' => "to_char(ordered_at, 'YYYY-MM')",
            default => "strftime('%Y-%m', ordered_at)",
        };
    }
}
