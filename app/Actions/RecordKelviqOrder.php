<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\Payment;
use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Carbon\Exceptions\InvalidFormatException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Keep the `payments` row for one Kelviq order in step with Kelviq.
 *
 * Kelviq describes an order in two vocabularies: snake_case in the `order.*`
 * webhooks, and camelCase in the `/orders/` API that `kelviq:sync-payments`
 * reads. Each has its own entry point here, and both end in the same write,
 * so a payment looks the same whichever of them recorded it.
 *
 * Keyed on Kelviq's order id, so a retried webhook, a webhook that arrives
 * after a sync, and a sync run twice all land on the one row. A description
 * older than the one already stored is ignored: webhooks are not delivered in
 * order, and a late `order.created` must not undo an `order.refunded`.
 *
 * The pilot is found by the merchant customer id Kelviq echoes back, which is
 * their uuid (see App\Actions\StartCheckout). An order for an id no account
 * has — a closed account, or a sale made in the dashboard — is still recorded,
 * without a pilot.
 */
final readonly class RecordKelviqOrder
{
    /**
     * `data.object` of an `order.created`, `order.updated` or `order.refunded`
     * event. `$eventAt` is the event's own `created_at`, for an order that
     * carries no `modified_on` of its own.
     *
     * @param  array<string, mixed>  $order
     *
     * @throws Throwable
     */
    public function fromWebhook(array $order, ?string $eventAt = null): ?Payment
    {
        return $this->record(
            orderId: $order['id'] ?? null,
            customerId: data_get($order, 'customer.customer_id'),
            attributes: [
                'kelviq_subscription_id' => $this->string($order['subscription_id'] ?? null),
                'status' => $this->string($order['status'] ?? null),
                'billing_type' => $this->string($order['billing_type'] ?? null),
                'is_renewal' => $this->bool($order['is_renewal'] ?? null),
                'plan_identifier' => $this->string(data_get($order, 'plan.identifier')),
                'amount_units' => $this->units($order['amount_total_units'] ?? null),
                'currency' => $this->currency($order['currency'] ?? null),
                'paid_at' => $this->time($order['paid_at'] ?? null),
            ],
            updatedAt: $this->time($order['modified_on'] ?? null) ?? $this->time($eventAt),
        );
    }

    /**
     * One entry of `GET /orders/`, read as of `$readAt`: the list carries no
     * modification time, so the moment it was read stands in for one.
     *
     * @param  array<string, mixed>  $order
     *
     * @throws Throwable
     */
    public function fromApi(array $order, CarbonInterface $readAt): ?Payment
    {
        return $this->record(
            orderId: $order['id'] ?? null,
            customerId: data_get($order, 'customer.customerId'),
            attributes: [
                'kelviq_subscription_id' => $this->string(data_get($order, 'subscription.id')),
                'status' => $this->string($order['status'] ?? null),
                'billing_type' => $this->string($order['billingType'] ?? null),
                'is_renewal' => $this->bool($order['isRenewal'] ?? null),
                'plan_identifier' => $this->string(data_get($order, 'plan.identifier')),
                'amount_units' => $this->units($order['amountTotalUnits'] ?? null),
                'currency' => $this->currency($order['saleCurrency'] ?? null),
                'paid_at' => $this->time($order['paidAt'] ?? null),
            ],
            updatedAt: $readAt,
        );
    }

    /**
     * Null when the order names no id, no customer or no status, which is
     * nothing a row can be kept for.
     *
     * Two deliveries about one order can land at once. Kelviq sends events
     * concurrently, and an `order.created` and the `order.updated` close
     * behind it are two events, so the webhook's claim on the event id does not
     * keep them apart. Read first and written after, the loser of that race
     * either failed on the unique key or wrote over the winner without having
     * compared against it. So the unique key elects which delivery creates the
     * row, and every other one compares and writes under the row's lock.
     *
     * @param  array<string, mixed>  $attributes
     *
     * @throws Throwable
     */
    private function record(mixed $orderId, mixed $customerId, array $attributes, ?CarbonInterface $updatedAt): ?Payment
    {
        $orderId = $this->string($orderId);
        $customerId = $this->string($customerId);

        if ($orderId === null || $customerId === null || $attributes['status'] === null) {
            return null;
        }

        $attributes['kelviq_customer_id'] = $customerId;
        $attributes['user_id'] = User::query()->where('uuid', $customerId)->first(['id'])?->id;

        // Outside the transaction below, as in RecordChallengeAttempt: an insert
        // that loses on the unique key inside one keeps a shared lock on the row
        // it hit, and two such deliveries asking to upgrade it would deadlock.
        $payment = Payment::query()->createOrFirst(
            ['kelviq_order_id' => $orderId],
            [...$attributes, 'kelviq_updated_at' => $updatedAt],
        );

        if ($payment->wasRecentlyCreated) {
            return $payment;
        }

        return DB::transaction(function () use ($orderId, $attributes, $updatedAt): Payment {
            $payment = Payment::query()
                ->where('kelviq_order_id', $orderId)
                ->lockForUpdate()
                ->firstOrFail();

            if ($updatedAt instanceof CarbonInterface && $payment->kelviq_updated_at?->isAfter($updatedAt)) {
                return $payment;
            }

            $payment->fill($attributes);
            $payment->kelviq_updated_at = $updatedAt ?? $payment->kelviq_updated_at;
            $payment->save();

            return $payment;
        });
    }

    private function string(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    private function bool(mixed $value): ?bool
    {
        return is_bool($value) ? $value : null;
    }

    private function units(mixed $value): ?int
    {
        return is_int($value) && $value >= 0 ? $value : null;
    }

    private function currency(mixed $value): ?string
    {
        return is_string($value) && mb_strlen($value) === 3 ? mb_strtoupper($value) : null;
    }

    /**
     * Moved to UTC, the zone stored times are read back in. Kept at whatever
     * offset it arrived with, a time is written as that offset's wall-clock
     * reading and comes back wrong by the offset — early enough, at `+02:00`,
     * for a later refund to look older than the payment it refunds.
     *
     * A time Carbon cannot read is kept as no time at all, like any other
     * field here that arrives in the wrong shape, but it is logged. Kelviq
     * signs what it sends, so an unreadable time means its format has changed,
     * and an unreadable `modified_on` quietly weakens the ordering guard. Only
     * that failure is caught: anything else is a bug, and answering it with
     * "no time" would hide it behind a result that looks like bad input.
     */
    private function time(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value)->utc();
        } catch (InvalidFormatException $invalidFormatException) {
            Log::warning('Kelviq sent a time that could not be read; recording it as absent.', [
                'value' => $value,
                'exception' => $invalidFormatException->getMessage(),
            ]);

            return null;
        }
    }
}
