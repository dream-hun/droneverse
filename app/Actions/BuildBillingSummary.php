<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\Plan;
use App\Models\User;
use App\Queries\DefaultSubscription;
use DateTimeInterface;
use LemonSqueezy\Laravel\LemonSqueezy;
use LemonSqueezy\Laravel\Order;
use LemonSqueezy\Laravel\Subscription;

/**
 * What the billing settings page shows one pilot about their own account.
 *
 * Everything here is read out of the local tables the Lemon Squeezy webhooks
 * keep in step, with no exceptions: this page makes no network call at all.
 * Under Paddle the next bill date had to be fetched, because only Paddle knew
 * it. Lemon Squeezy writes `renews_at` onto the subscription row, so the page
 * renders identically whether or not this environment has an API key.
 *
 * The trade is the amount. Paddle answered with the money as well as the date;
 * `renews_at` is a date alone, and nothing local can reconstruct what the next
 * charge will come to once proration, pauses and trials have moved it. The page
 * quotes the date and stays quiet about the number rather than guessing at it.
 */
final readonly class BuildBillingSummary
{
    public function __construct(private DefaultSubscription $subscriptions)
    {
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
         * A Lemon Squeezy subscription names the variant it sells on the row
         * itself, so the plan is read straight off it. Phase 7's per-seat
         * pricing will need a second subscription rather than a second line
         * item — there is no items table to look through here.
         */
        $priceId = $subscription->variant_id;
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
            'endsAt' => $this->iso($subscription->ends_at),
            'trialEndsAt' => $this->iso($subscription->trial_ends_at),
            /*
             * The date the next charge falls on, straight off the column the
             * webhook maintains. Reported unconditionally, including on a
             * cancelled subscription, where Lemon Squeezy leaves the last
             * renewal date in place: `cancelled`, `onGracePeriod` and `endsAt`
             * travel beside it, so the page has everything it needs to decide
             * whether a renewal date still means anything, and this action has
             * no business second-guessing what it renders.
             */
            'renewsAt' => $this->iso($subscription->renews_at),
            /*
             * Stored locally by the webhook, so the page can name the card on
             * file without asking anyone. Both null until a payment has been
             * taken — a trial that has never charged has no card to show.
             */
            'cardBrand' => $this->text($subscription->card_brand),
            'cardLastFour' => $this->text($subscription->card_last_four),
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
     * the pricing page quotes. A period with no configured Lemon Squeezy ID is
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
     * `isCurrent` is compared on the price ID rather than on the plan and period
     * names, so a subscription sold at a launch price matches nothing here and
     * the dialog opens with no period preselected. That is the honest answer:
     * moving off a launch variant gives it up, and the page should not imply the
     * pilot is already on the standard price.
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
                'formatted' => LemonSqueezy::formatAmount($amount, $this->currency(), options: ['min_fraction_digits' => 0]),
                'isCurrent' => $subscription->hasVariant($priceId),
            ];
        }

        return $variants;
    }

    /**
     * The currency the quoted amounts are denominated in.
     *
     * Read from config/plans.php rather than from the provider's config, for the
     * same reason BuildPricingCatalog does: it describes this application's
     * copy, and Lemon Squeezy is free to charge in another one.
     */
    private function currency(): string
    {
        $currency = config('plans.currency');

        return is_string($currency) ? $currency : 'USD';
    }

    /**
     * The pilot's own receipts, newest first.
     *
     * Lemon Squeezy hosts the receipt itself and hands us a URL to it, so there
     * is nothing to render or store on our side. `receiptUrl` is nullable
     * because an order that never completed has no receipt to link to.
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
                'id' => $order->lemon_squeezy_id,
                'orderNumber' => $order->order_number,
                'status' => $order->status,
                'total' => LemonSqueezy::formatAmount((int) $order->total, $order->currency),
                'receiptUrl' => $this->text($order->receipt_url),
                'refunded' => (bool) $order->refunded,
                'orderedAt' => $this->iso($order->ordered_at),
            ])
            ->all();
    }

    private function iso(mixed $date): ?string
    {
        return $date instanceof DateTimeInterface ? $date->format(DATE_ATOM) : null;
    }

    /**
     * An empty column is the same absence as a null one, and the page has one
     * branch for both.
     */
    private function text(?string $value): ?string
    {
        return $value === null || $value === '' ? null : $value;
    }
}
