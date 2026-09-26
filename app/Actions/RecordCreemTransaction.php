<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\Order;
use App\Models\Subscription;
use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\Exceptions\InvalidFormatException;

/**
 * Record one Creem transaction as a receipt, if it is not one already.
 *
 * This is how a renewal becomes revenue. `checkout.completed` carries the order
 * for the first payment and App\Actions\SyncCreemOrder writes it, but every
 * payment after that arrives as `subscription.paid`, which names the
 * transaction and does not carry it — so the transaction is read back from
 * Creem instead, by App\Actions\ReconcileCreemBilling.
 *
 * One row per transaction, keyed on the transaction. Not on the order: a Creem
 * subscription has one order, the checkout's, and every renewal is a further
 * transaction against it. Keying on the order made each renewal look like the
 * checkout arriving again, and every one of them was dropped.
 *
 * The first payment is the one transaction that is already on the books,
 * written by the checkout under the order's ID. It is recognised and the
 * transaction attached to that row rather than written beside it, so the same
 * money is never counted twice.
 *
 * Never overwrites the money on a row. The checkout's order is the better
 * record of that payment, and a transaction seen twice is the same payment
 * seen twice.
 */
final readonly class RecordCreemTransaction
{
    /**
     * Returns the order the transaction is recorded as, or null when it is not
     * a payment this application records.
     *
     * @param  array<string, mixed>  $transaction
     */
    public function handle(User $user, array $transaction): ?Order
    {
        $transactionId = ResolveCreemBillable::id($transaction, 'id');
        $status = $transaction['status'] ?? null;
        $currency = $transaction['currency'] ?? null;
        $amount = $this->cents($transaction['amount_paid'] ?? null) ?? $this->cents($transaction['amount'] ?? null);

        /*
         * Only money that actually landed is a receipt. A declined or pending
         * transaction is Creem retrying a card, and the refund states are
         * recorded against the order by App\Actions\RecordCreemRefund.
         */
        if ($transactionId === null || $status !== 'paid') {
            return null;
        }

        if (! is_string($currency) || $currency === '' || $amount === null) {
            return null;
        }

        $recorded = Order::query()->where('transaction_id', $transactionId)->first();

        if ($recorded instanceof Order) {
            return $recorded;
        }

        $orderId = ResolveCreemBillable::id($transaction, 'order');
        $subscriptionId = ResolveCreemBillable::id($transaction, 'subscription');
        $orderedAt = $this->date($transaction['created_at'] ?? null) ?? CarbonImmutable::now();

        $checkout = $this->checkoutPaidBy($orderId, $subscriptionId, $orderedAt);

        if ($checkout instanceof Order) {
            $checkout->forceFill(['transaction_id' => $transactionId])->save();

            return $checkout;
        }

        $subscription = $subscriptionId === null
            ? null
            : Subscription::query()->where('creem_id', $subscriptionId)->first();

        $productId = ResolveCreemBillable::id($transaction, 'product') ?? $subscription?->product_id;
        $customerId = ResolveCreemBillable::id($transaction, 'customer') ?? $subscription?->customer_id;

        if ($productId === null || $customerId === null) {
            return null;
        }

        $type = $transaction['type'] ?? null;

        return Order::query()->create([
            'creem_id' => $transactionId,
            'transaction_id' => $transactionId,
            'billable_id' => $user->getKey(),
            'billable_type' => $user->getMorphClass(),
            'checkout_id' => null,
            'customer_id' => $customerId,
            'product_id' => $productId,
            'subscription_id' => $subscriptionId,
            'currency' => $currency,
            'amount' => $amount,
            'status' => $status,
            'type' => is_string($type) ? $type : null,
            'ordered_at' => $orderedAt,
        ]);
    }

    /**
     * The checkout's order, when this transaction is the payment it recorded.
     *
     * Only a row not yet matched to a transaction can be, and only one written
     * within a day of it: the first payment lands moments after the checkout
     * completes, a renewal a whole billing period later. Matched by the order
     * the transaction names where it names one, and by the subscription where
     * it does not.
     */
    private function checkoutPaidBy(?string $orderId, ?string $subscriptionId, CarbonImmutable $paidAt): ?Order
    {
        if ($orderId === null && $subscriptionId === null) {
            return null;
        }

        $orders = Order::query()
            ->whereNull('transaction_id')
            ->whereNotNull('checkout_id')
            ->whereBetween('ordered_at', [$paidAt->subDay(), $paidAt->addDay()]);

        if ($orderId !== null) {
            $orders->where('creem_id', $orderId);
        } else {
            $orders->where('subscription_id', $subscriptionId);
        }

        return $orders->first();
    }

    /**
     * Creem's amounts are integer cents, which JSON may still decode as a
     * float with nothing after the point.
     */
    private function cents(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_float($value) && floor($value) === $value) {
            return (int) $value;
        }

        return null;
    }

    /**
     * Creem's timestamps are ISO strings on most objects and epoch numbers on
     * transactions — milliseconds in every example, but a value too small to
     * be milliseconds after 1973 is read as seconds rather than as 1970.
     */
    private function date(mixed $value): ?CarbonImmutable
    {
        if ((is_int($value) || is_float($value)) && $value > 0) {
            return $value >= 100_000_000_000
                ? CarbonImmutable::createFromTimestampMs($value)
                : CarbonImmutable::createFromTimestamp($value);
        }

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
