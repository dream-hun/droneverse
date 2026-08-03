<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\Plan;
use App\Enums\PlanChange;
use App\Models\User;
use LemonSqueezy\Laravel\Subscription;

/**
 * Move an existing subscription onto another plan or billing period.
 *
 * The alternative — a second checkout — is the thing this exists to prevent. A
 * pilot who buys Team while holding Pro ends up billed for both, entitled to
 * nothing they were not already entitled to, and with two renewal dates to
 * cancel. One subscription changes what it sells instead.
 *
 * The price is chosen by ResolveCheckoutPrice, exactly as a first purchase is:
 * the request names a tier and a period, never an ID, and any launch pricing
 * that applies to a new buyer applies to a switching one on the same terms.
 *
 * Prorated, and not invoiced immediately. Lemon Squeezy works out what the
 * unused remainder of the current period is worth and settles the difference on
 * the next renewal — an upgrade is credited for what has already been paid, and
 * a downgrade is not charged again to receive less. Taking the difference on the
 * spot would make the upgrade button charge a card without warning, which is a
 * refund conversation on a screen meant to be reassuring.
 */
final readonly class SwapSubscription
{
    public function __construct(private ResolveCheckoutPrice $prices)
    {
        //
    }

    /**
     * Throws when Lemon Squeezy will not make the change — no API key, a variant
     * the store does not recognize, or the API answering with an error. Callers
     * reached from a request must turn that into something a pilot can read
     * rather than letting it become a 500.
     */
    public function handle(User $user, Subscription $subscription, Plan $plan, string $variant): PlanChange
    {
        $priceId = $this->prices->handle($user, $plan, $variant);
        $productId = $this->productId($plan, $subscription);

        if ($priceId === null || $productId === null) {
            return PlanChange::Unavailable;
        }

        if ($subscription->hasVariant($priceId)) {
            return PlanChange::Unchanged;
        }

        $subscription->swap($productId, $priceId);

        /*
         * swap() syncs the new variant onto the row, so what this pilot is
         * entitled to has already changed by the time the page re-renders. The
         * `subscription_updated` webhook says the same thing again a moment
         * later; neither is waiting on the other.
         */
        $user->forgetPlan();

        return PlanChange::Swapped;
    }

    /**
     * The product the new variant belongs to.
     *
     * Configured per plan, because Lemon Squeezy's update endpoint refuses a
     * variant that does not belong to the product named beside it — this is the
     * one operation in the whole integration that needs a product at all.
     *
     * The fallback covers half of switching. A pilot moving from monthly to
     * yearly on the plan they already hold stays inside one product whatever it
     * is called here, so the subscription's own `product_id` is the right answer
     * and an environment that has never set `plans.products` can still offer
     * that. Moving between tiers genuinely needs the configuration, and says so
     * by refusing rather than by sending Pro's product with Team's variant.
     */
    private function productId(Plan $plan, Subscription $subscription): ?string
    {
        $productId = $plan->productId();

        if ($productId !== null) {
            return $productId;
        }

        return Plan::fromPriceId($subscription->variant_id) === $plan
            ? $subscription->product_id
            : null;
    }
}
