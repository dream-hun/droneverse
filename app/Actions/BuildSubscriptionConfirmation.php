<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\Plan;
use App\Models\User;
use App\Queries\DefaultSubscription;
use DateTimeInterface;
use LemonSqueezy\Laravel\Subscription;

/**
 * What the thank-you page tells a pilot about the subscription they just paid
 * for.
 *
 * Local tables only, like BuildBillingSummary — but read at a moment that page
 * never sees. A buyer reaches this straight out of the checkout overlay, and
 * the subscription they paid for is created by a webhook that has not
 * necessarily landed yet. So the interesting answer here is not the plan; it is
 * `pending`, which says the money is gone and the row is not here, and which
 * the page turns into a poll rather than into "you are on Starter".
 *
 * Nothing is taken from the request. The page cannot be told which plan was
 * bought, because a query string is written by whoever is looking at it, and a
 * pilot on Starter should not be able to read their own confirmation of Team.
 * Until the webhook lands the page says only that something is being activated.
 */
final readonly class BuildSubscriptionConfirmation
{
    public function __construct(private DefaultSubscription $subscriptions)
    {
        //
    }

    /**
     * @return array{plan: array<string, mixed>, subscription: array<string, mixed>|null, highlights: array<int, string>, pending: bool}
     */
    public function handle(User $user): array
    {
        $subscription = $this->subscriptions->for($user);
        $active = $subscription?->valid() === true;
        $plan = $user->plan();

        return [
            'plan' => [
                'value' => $plan->value,
                'label' => $plan->label(),
                'isPaid' => $plan->isPaid(),
            ],
            'subscription' => $active && $subscription instanceof Subscription
                ? $this->subscription($subscription)
                : null,
            /*
             * What the plan unlocks, in the same words the pricing card sold it
             * in — the buyer has just read them, and a confirmation that
             * paraphrases the pitch invites a second reading of whether it was
             * the pitch they bought. Empty while there is nothing confirmed to
             * list.
             */
            'highlights' => $active ? $plan->highlights() : [],
            /*
             * The webhook gap: paid, and not yet granted.
             *
             * A comped account is deliberately not pending — `plan()` already
             * resolves it to a paid tier with no subscription behind it, and
             * polling for a row that will never be written would leave that
             * pilot watching a spinner forever.
             */
            'pending' => ! $active && ! $plan->isPaid(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function subscription(Subscription $subscription): array
    {
        $priceId = $subscription->variant_id;
        $plan = Plan::fromPriceId($priceId);

        return [
            'planLabel' => $plan?->label(),
            'variant' => $plan?->variantFor($priceId),
            'onTrial' => $subscription->onTrial(),
            'trialEndsAt' => $this->iso($subscription->trial_ends_at),
            'renewsAt' => $this->iso($subscription->renews_at),
            /*
             * Written by the webhook once a payment has actually been taken, so
             * both are null on a subscription that started on a trial. The page
             * names the card when it can and says nothing when it cannot.
             */
            'cardBrand' => $this->text($subscription->card_brand),
            'cardLastFour' => $this->text($subscription->card_last_four),
        ];
    }

    private function iso(mixed $date): ?string
    {
        return $date instanceof DateTimeInterface ? $date->format(DATE_ATOM) : null;
    }

    private function text(?string $value): ?string
    {
        return $value === null || $value === '' ? null : $value;
    }
}
