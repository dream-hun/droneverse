<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin;

use App\Actions\DescribeCreemProduct;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * One subscription as the finance screen lists it.
 *
 * `entitles` is App\Models\Subscription::valid()'s answer rather than a
 * reading of the status, so a scheduled cancellation whose period has run out
 * shows as no longer entitling even if Creem's `canceled` webhook is late.
 *
 * @phpstan-type Row array{creemId: string, pilot: array{uuid: string, name: string, email: string}|null, product: string, status: string, entitles: bool, units: int, renewsAt: string|null, endsAt: string|null, createdAt: string|null}
 */
final class AdminSubscriptionResource
{
    /**
     * @param  Collection<int, Subscription>  $subscriptions  with `billable` eager loaded
     * @return array<int, Row>
     */
    public static function collection(Collection $subscriptions): array
    {
        $products = resolve(DescribeCreemProduct::class);

        return $subscriptions->map(fn (Subscription $subscription): array => [
            'creemId' => $subscription->creem_id,
            'pilot' => $subscription->billable instanceof User
                ? ['uuid' => $subscription->billable->uuid, 'name' => $subscription->billable->name, 'email' => $subscription->billable->email]
                : null,
            'product' => $products->handle($subscription->product_id),
            'status' => $subscription->status,
            'entitles' => $subscription->valid(),
            'units' => $subscription->units,
            'renewsAt' => $subscription->cancelled() ? null : $subscription->renews_at?->toIso8601String(),
            'endsAt' => $subscription->endsAt()?->toIso8601String(),
            'createdAt' => $subscription->created_at?->toIso8601String(),
        ])->values()->all();
    }
}
