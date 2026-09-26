<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\Order;
use Carbon\CarbonImmutable;

/**
 * Mark the order a refund was issued against.
 *
 * Only ever an update. A refund for an order this application never recorded —
 * a payment taken outside it, or one that predates the Creem integration — has
 * no row to amend, and inventing one would put a receipt on a billing page for
 * something the pilot never bought here.
 *
 * The amount is stored beside the flag because Creem allows partial refunds:
 * "refunded" alone would tell a pilot their $190 came back when $19 did.
 */
final readonly class RecordCreemRefund
{
    /**
     * @param  array<string, mixed>  $refund  The `object` of a
     *                                        `refund.created` event.
     */
    public function handle(array $refund): ?Order
    {
        $order = $this->refundedOrder($refund);

        if (! $order instanceof Order) {
            return null;
        }

        $amount = $refund['refund_amount'] ?? null;

        $order->forceFill([
            'refunded' => true,
            'refunded_amount' => is_int($amount) ? $amount : null,
            'refunded_at' => CarbonImmutable::now(),
        ])->save();

        return $order;
    }

    /**
     * The receipt a refund was issued against.
     *
     * By transaction first, because that names the one payment refunded: a
     * subscription's renewals all share the checkout's order, so the order
     * alone would mark the first payment refunded whichever month came back.
     * By order after that, for a checkout written before its transaction was
     * known.
     *
     * @param  array<string, mixed>  $refund
     */
    private function refundedOrder(array $refund): ?Order
    {
        $transactionId = ResolveCreemBillable::id($refund, 'transaction');

        if ($transactionId !== null) {
            $order = Order::query()->where('transaction_id', $transactionId)->first();

            if ($order instanceof Order) {
                return $order;
            }
        }

        $orderId = ResolveCreemBillable::id($refund, 'order');

        return $orderId === null ? null : Order::query()->where('creem_id', $orderId)->first();
    }
}
