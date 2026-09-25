<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\Order;
use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\Exceptions\InvalidFormatException;

/**
 * Record a completed order as a receipt on the pilot's billing page.
 *
 * Keyed on Creem's order ID and written whole, so a redelivered
 * `checkout.completed` rewrites the same row rather than inserting a second
 * one. The `refunded` columns are deliberately not written here — a redelivery
 * arriving after a refund must not un-refund the order it describes — and are
 * App\Actions\RecordCreemRefund's alone.
 */
final readonly class SyncCreemOrder
{
    public function __construct(private SyncCreemCustomer $customers)
    {
        //
    }

    /**
     * Returns null when the checkout carries no order, which is what a checkout
     * that has not been paid for looks like.
     *
     * @param  array<string, mixed>  $checkout  The `object` of a
     *                                          `checkout.completed` event.
     */
    public function handle(User $user, array $checkout): ?Order
    {
        $order = $checkout['order'] ?? null;

        if (! is_array($order)) {
            return null;
        }

        $creemId = ResolveCreemBillable::id($order, 'id');
        $productId = ResolveCreemBillable::id($order, 'product') ?? ResolveCreemBillable::id($checkout, 'product');
        $customerId = ResolveCreemBillable::id($order, 'customer') ?? ResolveCreemBillable::id($checkout, 'customer');
        $currency = $order['currency'] ?? null;
        $amount = $order['amount'] ?? null;

        if ($creemId === null || $productId === null || $customerId === null) {
            return null;
        }

        if (! is_string($currency) || ! is_int($amount)) {
            return null;
        }

        $this->customers->handle($user, $checkout);

        $status = $order['status'] ?? null;
        $type = $order['type'] ?? null;

        return Order::query()->updateOrCreate(
            ['creem_id' => $creemId],
            [
                'billable_id' => $user->getKey(),
                'billable_type' => $user->getMorphClass(),
                'checkout_id' => ResolveCreemBillable::id($checkout, 'id'),
                'customer_id' => $customerId,
                'product_id' => $productId,
                'subscription_id' => ResolveCreemBillable::id($checkout, 'subscription'),
                'currency' => $currency,
                'amount' => $amount,
                'status' => is_string($status) ? $status : 'unknown',
                'type' => is_string($type) ? $type : null,
                /*
                 * Creem timestamps the order rather than the checkout, and an
                 * order with no date is one this page could not sort. Falling
                 * back to now is honest to the minute a delivery arrives, which
                 * is close enough for a receipt list and better than a row that
                 * cannot be listed at all.
                 */
                'ordered_at' => $this->date($order, 'created_at') ?? CarbonImmutable::now(),
            ],
        );
    }

    /**
     * @param  array<mixed>  $order
     */
    private function date(array $order, string $key): ?CarbonImmutable
    {
        $value = $order[$key] ?? null;

        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value);
        } catch (InvalidFormatException) {
            return null;
        }
    }
}
