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
 * transaction and does not carry it — so before this, a renewal moved the
 * subscription's dates and left nothing on the finance pages. The transaction
 * is read back from Creem instead, by App\Actions\ReconcileCreemBilling.
 *
 * Keyed on the transaction's order where it has one, so the first payment of a
 * subscription lands on the row `checkout.completed` already wrote rather than
 * beside it, and a refund — which names the order — still finds it. A renewal
 * with no order is keyed on the transaction itself.
 *
 * Never overwrites. The row the checkout wrote is the better record of that
 * payment, and a transaction seen twice is the same payment seen twice.
 */
final readonly class RecordCreemTransaction
{
    /**
     * Only money that actually landed is a receipt. A declined or pending
     * transaction is Creem retrying a card, and the refund states are recorded
     * against the order by App\Actions\RecordCreemRefund when they happen.
     */
    private const array RECORDED_STATUSES = ['paid'];

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
        $amount = $transaction['amount_paid'] ?? $transaction['amount'] ?? null;

        if ($transactionId === null || ! in_array($status, self::RECORDED_STATUSES, true)) {
            return null;
        }

        if (! is_string($currency) || $currency === '' || ! is_int($amount)) {
            return null;
        }

        $orderId = ResolveCreemBillable::id($transaction, 'order');
        $key = $orderId ?? $transactionId;

        $existing = Order::query()->where('creem_id', $key)->first();

        if ($existing instanceof Order) {
            return $existing;
        }

        $subscriptionId = ResolveCreemBillable::id($transaction, 'subscription');
        $subscription = $subscriptionId === null
            ? null
            : Subscription::query()->where('creem_id', $subscriptionId)->first();

        $productId = ResolveCreemBillable::id($transaction, 'product') ?? $subscription?->product_id;
        $customerId = ResolveCreemBillable::id($transaction, 'customer') ?? $subscription?->customer_id;

        if ($productId === null || $customerId === null) {
            return null;
        }

        $orderedAt = $this->date($transaction['created_at'] ?? null) ?? CarbonImmutable::now();

        if ($orderId === null && $this->alreadyRecordedByCheckout($subscriptionId, $orderedAt)) {
            return null;
        }

        $type = $transaction['type'] ?? null;

        return Order::query()->create([
            'creem_id' => $key,
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
     * Whether a transaction with no order is the first payment of a
     * subscription whose checkout was already recorded.
     *
     * The one way the keying above could count a payment twice: Creem is not
     * documented to put the order on the first transaction, and if it does not,
     * that transaction and the checkout's order are the same money under two
     * IDs. They are told apart by time — a renewal is a billing period after
     * the checkout, the first payment is within moments of it.
     */
    private function alreadyRecordedByCheckout(?string $subscriptionId, CarbonImmutable $orderedAt): bool
    {
        if ($subscriptionId === null) {
            return false;
        }

        return Order::query()
            ->where('subscription_id', $subscriptionId)
            ->whereNotNull('checkout_id')
            ->whereBetween('ordered_at', [$orderedAt->subDay(), $orderedAt->addDay()])
            ->exists();
    }

    /**
     * Creem's timestamps are ISO strings on most objects and epoch milliseconds
     * on some; both are accepted.
     */
    private function date(mixed $value): ?CarbonImmutable
    {
        if (is_int($value) && $value > 0) {
            return CarbonImmutable::createFromTimestampMs($value);
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
