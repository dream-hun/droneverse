<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\Plan;
use App\Models\User;
use DateTimeInterface;
use Laravel\Paddle\Cashier;
use Laravel\Paddle\Subscription;
use Laravel\Paddle\SubscriptionItem;
use Laravel\Paddle\Transaction;
use Throwable;

/**
 * What the billing settings page shows one pilot about their own account.
 *
 * Everything here is read out of the local tables Cashier's webhooks keep in
 * step, with a single deliberate exception: the next bill date, which only
 * Paddle knows and which is allowed to come back empty.
 */
final readonly class BuildBillingSummary
{
    /**
     * @return array{plan: array<string, mixed>, subscription: array<string, mixed>|null, transactions: array<int, array<string, mixed>>}
     */
    public function handle(User $user): array
    {
        $subscription = $this->currentSubscription($user);
        $plan = $user->plan();

        return [
            'plan' => [
                'value' => $plan->value,
                'label' => $plan->label(),
                'isPaid' => $plan->isPaid(),
                'source' => $this->source($user, $subscription),
            ],
            'subscription' => $subscription instanceof Subscription
                ? $this->subscription($subscription)
                : null,
            'transactions' => $this->transactions($user),
        ];
    }

    /**
     * The pilot's default subscription.
     *
     * Queried rather than read off `$user->subscriptions`, for the same reason
     * ResolvePlanForUser does: the relation is unloaded on a freshly
     * authenticated user, and the lazy-loading guard is armed everywhere but
     * production.
     */
    private function currentSubscription(User $user): ?Subscription
    {
        return Subscription::query()
            ->whereMorphedTo('billable', $user)
            ->where('type', Subscription::DEFAULT_TYPE)
            ->latest('id')
            ->first();
    }

    /**
     * Why the pilot is on the plan they are on.
     *
     * The page reads this to know what it may offer: there is no subscription
     * to cancel behind a comped account, and offering one would be a button
     * that either lies or does damage.
     */
    private function source(User $user, ?Subscription $subscription): string
    {
        return match (true) {
            $user->plan_override !== null => 'override',
            $subscription?->valid() === true => 'subscription',
            default => 'none',
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function subscription(Subscription $subscription): array
    {
        $priceId = $this->subscribedPriceId($subscription);
        $plan = Plan::fromPriceId($priceId);

        return [
            'status' => $subscription->status,
            'planLabel' => $plan?->label(),
            'variant' => $plan?->variantFor($priceId),
            'valid' => $subscription->valid(),
            'onGracePeriod' => $subscription->onGracePeriod(),
            'canceled' => $subscription->canceled(),
            'paused' => $subscription->paused(),
            'pastDue' => $subscription->pastDue(),
            'onTrial' => $subscription->onTrial(),
            'endsAt' => $this->iso($subscription->ends_at),
            'trialEndsAt' => $this->iso($subscription->trial_ends_at),
            'nextPayment' => $this->nextPayment($subscription),
        ];
    }

    /**
     * The amount and date of the next charge, or null if Paddle will not say.
     *
     * This is the one call on the page that leaves the building, and it is the
     * one piece of information nothing local can reconstruct — proration,
     * pauses and trials all move the date. A billing page that renders without
     * it is missing a line; a billing page that 500s because Paddle is down, or
     * because this environment has no API key, is missing everything.
     *
     * @return array{amount: string, date: string|null}|null
     */
    private function nextPayment(Subscription $subscription): ?array
    {
        if (! $subscription->valid() || $subscription->canceled() || $subscription->paused()) {
            return null;
        }

        try {
            $payment = $subscription->nextPayment();
        } catch (Throwable) {
            return null;
        }

        return $payment === null ? null : [
            'amount' => $payment->amount(),
            'date' => $this->iso($payment->date),
        ];
    }

    /**
     * The price the subscription is currently billed at.
     *
     * A subscription can carry several items once Phase 7's per-seat pricing
     * lands. The first is the one that names the plan; the seat item rides
     * alongside it and says nothing about which tier was bought.
     *
     * Ordered explicitly, because "the first" is only true of an ordered query
     * — an unordered one returns whichever row the database hands back, and
     * naming the subscription from the seat price would report the wrong tier.
     */
    private function subscribedPriceId(Subscription $subscription): ?string
    {
        $priceId = SubscriptionItem::query()
            ->where('subscription_id', $subscription->id)
            ->orderBy('id')
            ->value('price_id');

        return is_string($priceId) && $priceId !== '' ? $priceId : null;
    }

    /**
     * The pilot's own receipts, newest first.
     *
     * @return array<int, array<string, mixed>>
     */
    private function transactions(User $user): array
    {
        return Transaction::query()
            ->whereMorphedTo('billable', $user)
            ->latest('billed_at')
            ->limit(24)
            ->get()
            ->map(fn (Transaction $transaction): array => [
                'id' => $transaction->paddle_id,
                'invoiceNumber' => $transaction->invoice_number,
                'status' => $transaction->status,
                'total' => Cashier::formatAmount((int) $transaction->total, $transaction->currency),
                'billedAt' => $this->iso($transaction->billed_at),
            ])
            ->all();
    }

    private function iso(mixed $date): ?string
    {
        return $date instanceof DateTimeInterface ? $date->format(DATE_ATOM) : null;
    }
}
