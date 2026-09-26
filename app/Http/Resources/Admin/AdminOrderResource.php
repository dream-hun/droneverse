<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin;

use App\Actions\DescribeCreemProduct;
use App\Actions\FormatMoney;
use App\Models\Order;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * One order as the finance ledger lists it.
 *
 * Money is formatted here, once, by App\Actions\FormatMoney, so the page never
 * divides by a hundred on its way to rendering something. The pilot is named
 * when the billable is an account; anything else Creem might one day bill — a
 * classroom — is shown without one rather than guessed at.
 *
 * @phpstan-type Row array{creemId: string, pilot: array{uuid: string, name: string, email: string}|null, product: string, amount: string, status: string, refunded: bool, refundedAmount: string|null, refundedAt: string|null, orderedAt: string}
 */
final class AdminOrderResource
{
    /**
     * @param  Collection<int, Order>  $orders  with `billable` eager loaded
     * @return array<int, Row>
     */
    public static function collection(Collection $orders): array
    {
        $money = resolve(FormatMoney::class);
        $products = resolve(DescribeCreemProduct::class);

        return $orders->map(fn (Order $order): array => [
            'creemId' => $order->creem_id,
            'pilot' => $order->billable instanceof User
                ? ['uuid' => $order->billable->uuid, 'name' => $order->billable->name, 'email' => $order->billable->email]
                : null,
            'product' => $products->handle($order->product_id),
            'amount' => $money->handle($order->amount, $order->currency),
            'status' => $order->status,
            'refunded' => $order->refunded,
            'refundedAmount' => $order->refunded
                ? $money->handle($order->refunded_amount ?? $order->amount, $order->currency)
                : null,
            'refundedAt' => $order->refunded_at?->toIso8601String(),
            'orderedAt' => $order->ordered_at->toIso8601String(),
        ])->values()->all();
    }
}
