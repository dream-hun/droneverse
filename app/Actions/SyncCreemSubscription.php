<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\SubscriptionStatus;
use App\Models\Subscription;
use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\Exceptions\InvalidFormatException;

/**
 * Write what a webhook says about a subscription onto the local copy of it.
 *
 * One method for every subscription event Creem publishes, because they all
 * carry the same subscription object and differ only in why it was sent. There
 * is no per-event handler and no insert-versus-update branch: the row is keyed
 * on Creem's ID for the subscription and written whole every time, which is
 * what makes a redelivered event — Creem retries anything that is not a 2xx,
 * five times over six hours — cost nothing but a second write of the same
 * values.
 *
 * That idempotency is structural rather than checked. The Lemon Squeezy
 * integration this replaces needed a middleware in front of its webhook purely
 * to intercept redeliveries, because its create handlers inserted against a
 * unique index and answered 500 to the second attempt, which earned another
 * attempt. Nothing here can do that.
 */
final readonly class SyncCreemSubscription
{
    public function __construct(private SyncCreemCustomer $customers)
    {
        //
    }

    /**
     * Returns null when the payload names no subscription — which is what a
     * one-off purchase's `checkout.completed` looks like.
     *
     * @param  array<string, mixed>  $subscription  The `object` of a
     *                                              `subscription.*` event, or the `subscription` of a checkout.
     */
    public function handle(User $user, array $subscription): ?Subscription
    {
        $creemId = ResolveCreemBillable::id($subscription, 'id');
        $productId = ResolveCreemBillable::id($subscription, 'product');
        $customerId = ResolveCreemBillable::id($subscription, 'customer');
        $status = $subscription['status'] ?? null;

        if ($creemId === null || $productId === null || $customerId === null || ! is_string($status)) {
            return null;
        }

        $this->customers->handle($user, $subscription);

        $periodEnd = $this->date($subscription, 'current_period_end_date');

        $record = Subscription::query()->updateOrCreate(
            ['creem_id' => $creemId],
            [
                'billable_id' => $user->getKey(),
                'billable_type' => $user->getMorphClass(),
                'type' => Subscription::DEFAULT_TYPE,
                'customer_id' => $customerId,
                'product_id' => $productId,
                'status' => $status,
                'units' => $this->units($subscription),
                /*
                 * Creem publishes no trial end date of its own: a trialing
                 * subscription's current period is the trial, and it becomes a
                 * paid period at the same instant. So this is that date while
                 * the trial is running, and left alone afterwards rather than
                 * nulled — a page saying when the trial ended is still telling
                 * the truth once it has.
                 */
                ...($status === SubscriptionStatus::Trialing->value && $periodEnd instanceof CarbonImmutable
                    ? ['trial_ends_at' => $periodEnd]
                    : []),
                'renews_at' => $this->date($subscription, 'next_transaction_date'),
                'current_period_start_at' => $this->date($subscription, 'current_period_start_date'),
                'current_period_end_at' => $periodEnd,
                'canceled_at' => $this->date($subscription, 'canceled_at'),
            ],
        );

        /*
         * The plan this pilot is entitled to is memoised on the User instance,
         * and this one was loaded before the row changed. Nothing renders after
         * a webhook, but the same Action runs from App\Actions\SwapSubscription
         * where something does.
         */
        $user->forgetPlan();

        return $record;
    }

    /**
     * How many seats this subscription bills for.
     *
     * Creem carries the count on the subscription's items rather than on the
     * subscription, and sends no items at all on some events — a payload
     * without them is not a subscription that dropped to zero seats, so the
     * fallback is one rather than nothing.
     *
     * @param  array<string, mixed>  $subscription
     */
    private function units(array $subscription): int
    {
        $units = data_get($subscription, 'items.0.units');

        return is_int($units) && $units > 0 ? $units : 1;
    }

    /**
     * @param  array<string, mixed>  $subscription
     */
    private function date(array $subscription, string $key): ?CarbonImmutable
    {
        $value = $subscription[$key] ?? null;

        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value);
        } catch (InvalidFormatException) {
            /*
             * A date we cannot read is dropped rather than allowed to abort the
             * whole delivery. The status is the part that decides entitlements;
             * losing a renewal date costs a line of copy on a settings page.
             */
            return null;
        }
    }
}
