<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\Plan;
use App\Models\Order;
use App\Models\Subscription;
use App\Models\User;
use App\Queries\DefaultSubscription;
use DateTimeInterface;

/**
 * What the billing settings page shows one pilot about their own account.
 *
 * Everything here is read out of the local tables the Creem webhooks keep in
 * step, with no exceptions: this page makes no network call at all. Under
 * Paddle the next bill date had to be fetched, because only Paddle knew it.
 * Creem sends `next_transaction_date` on every subscription event, so the page
 * renders identically whether or not this environment has an API key — and
 * whether or not Creem is answering today.
 *
 * The trade is the amount, and the card. Paddle answered with the money as well
 * as the date; a renewal date is a date alone, and nothing local can
 * reconstruct what the next charge will come to once proration, seats and
 * trials have moved it. Creem publishes no card brand or last four at all,
 * anywhere — the card on file is the customer portal's business, which is
 * exactly where the page's "update payment method" button sends a pilot who
 * wants to see it. The page quotes what it knows and stays quiet about the
 * rest rather than guessing at either.
 */
final readonly class BuildBillingSummary
{
    public function __construct(
        private DefaultSubscription $subscriptions,
        private FormatMoney $money,
    ) {
        //
    }

    /**
     * @return array{plan: array<string, mixed>, subscription: array<string, mixed>|null, switchable: array<int, array<string, mixed>>, orders: array<int, array<string, mixed>>}
     */
    public function handle(User $user): array
    {
        $subscription = $this->subscriptions->for($user);
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
            'switchable' => $this->switchable($subscription),
            'orders' => $this->orders($user),
        ];
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
        /*
         * A Creem subscription names the product it sells on the row itself, so
         * the plan is read straight off it. Phase 7's classroom seats are a
         * unit count on this same subscription rather than a second one — there
         * is no items table to look through here.
         */
        $priceId = $subscription->product_id;
        $plan = Plan::fromPriceId($priceId);

        return [
            'status' => $subscription->status,
            'planLabel' => $plan?->label(),
            'variant' => $plan?->variantFor($priceId),
            'valid' => $subscription->valid(),
            'onGracePeriod' => $subscription->onGracePeriod(),
            'cancelled' => $subscription->cancelled(),
            'paused' => $subscription->paused(),
            'pastDue' => $subscription->pastDue(),
            'onTrial' => $subscription->onTrial(),
            /*
             * Only ever set once the subscription is actually ending. A renewal
             * date is not an end date, and App\Models\Subscription is careful
             * not to hand one over as though it were.
             */
            'endsAt' => $this->iso($subscription->endsAt()),
            'trialEndsAt' => $this->iso($subscription->trial_ends_at),
            /*
             * The date the next charge falls on, straight off the column the
             * webhook maintains. Reported unconditionally, including on a
             * cancelled subscription, where Creem leaves the last known
             * transaction date in place: `cancelled`, `onGracePeriod` and
             * `endsAt` travel beside it, so the page has everything it needs to
             * decide whether a renewal date still means anything, and this
             * action has no business second-guessing what it renders.
             */
            'renewsAt' => $this->iso($subscription->renews_at),
        ];
    }

    /**
     * Every plan and billing period this pilot could move their subscription
     * onto, priced, with the one they are on already marked.
     *
     * Empty unless there is a subscription to move — a comped account has no
     * billing to change, and a pilot with no subscription at all is buying one
     * from the pricing page rather than switching. The page renders the whole
     * change-plan control from this, so an empty array is also how it knows not
     * to offer it.
     *
     * Every period a plan sells is listed, priced from the same `plans.amounts`
     * the pricing page quotes. A period with no configured Creem product is
     * left out entirely rather than shown and refused: this is a short list in a
     * dialog, not a marketing page, and there is nothing to argue for by
     * advertising something that cannot be selected.
     *
     * @return array<int, array<string, mixed>>
     */
    private function switchable(?Subscription $subscription): array
    {
        if (! $subscription instanceof Subscription || ! $this->subscriptions->isSwitchable($subscription)) {
            return [];
        }

        $plans = [];

        foreach (Plan::cases() as $plan) {
            if (! $plan->isSelfServe()) {
                continue;
            }

            $variants = $this->variants($plan, $subscription);

            if ($variants !== []) {
                $plans[] = [
                    'value' => $plan->value,
                    'label' => $plan->label(),
                    'tagline' => $plan->tagline(),
                    'variants' => $variants,
                ];
            }
        }

        return $plans;
    }

    /**
     * The billing periods of one plan that can actually be switched to.
     *
     * `isCurrent` is compared on the product ID rather than on the plan and
     * period names, so a subscription sold at a launch price matches nothing
     * here and the dialog opens with no period preselected. That is the honest
     * answer: moving off a launch product gives it up, and the page should not
     * imply the pilot is already on the standard price.
     *
     * @return array<int, array<string, mixed>>
     */
    private function variants(Plan $plan, Subscription $subscription): array
    {
        $variants = [];

        foreach ($plan->variants() as $variant) {
            $priceId = $plan->priceId($variant);
            $amount = $plan->amount($variant);

            if ($priceId === null) {
                continue;
            }

            if ($amount === null) {
                continue;
            }

            $variants[] = [
                'value' => $variant,
                'amount' => $amount,
                'formatted' => $this->money->handle($amount, $this->currency(), minFractionDigits: 0),
                'isCurrent' => $subscription->hasProduct($priceId),
            ];
        }

        return $variants;
    }

    /**
     * The currency the quoted amounts are denominated in.
     *
     * Read from config/plans.php rather than from the provider's config, for the
     * same reason BuildPricingCatalog does: it describes this application's
     * copy, and Creem is free to charge in another one.
     */
    private function currency(): string
    {
        $currency = config('plans.currency');

        return is_string($currency) ? $currency : 'USD';
    }

    /**
     * The pilot's own receipts, newest first.
     *
     * Money and dates only. Creem publishes no per-order receipt URL — invoices
     * live behind the customer portal, which is a magic link minted per request
     * rather than a stable address — so the page links to the portal once
     * instead of carrying a document link per row.
     *
     * `refundedAmount` travels beside `refunded` because Creem allows partial
     * refunds, and a flag on its own would tell a pilot their whole year came
     * back when a month did.
     *
     * @return array<int, array<string, mixed>>
     */
    private function orders(User $user): array
    {
        return Order::query()
            ->whereMorphedTo('billable', $user)
            ->latest('ordered_at')
            ->limit(24)
            ->get()
            ->map(fn (Order $order): array => [
                'id' => $order->creem_id,
                'status' => $order->status,
                'total' => $this->money->handle($order->amount, $order->currency),
                'refunded' => $order->refunded,
                'refundedTotal' => $order->refunded_amount === null
                    ? null
                    : $this->money->handle($order->refunded_amount, $order->currency),
                'orderedAt' => $this->iso($order->ordered_at),
            ])
            ->all();
    }

    private function iso(mixed $date): ?string
    {
        return $date instanceof DateTimeInterface ? $date->format(DATE_ATOM) : null;
    }
}
