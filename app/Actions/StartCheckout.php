<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\Plan;
use App\Models\User;

/**
 * Open a Lemon Squeezy checkout for a plan, server-side.
 *
 * Returns the checkout URL Lemon Squeezy mints for the resolved variant. The
 * browser is handed a checkout it cannot alter the price of — it never learns
 * an ID, and it has nothing to submit but the plan it clicked. The URL is
 * already scoped to this buyer and this variant by the time it leaves here.
 */
final readonly class StartCheckout
{
    public function __construct(private ResolveCheckoutPrice $prices)
    {
        //
    }

    /**
     * Returns null when the plan is not for sale in this environment; see
     * ResolveCheckoutPrice for what that covers.
     *
     * Throws when Lemon Squeezy will not mint a checkout — no API key, no
     * store, or the API answering with an error. That is new: the old Paddle
     * path built its option bag locally and could not fail. Callers reached
     * from a request must turn it into something a buyer can read rather than
     * letting it become a 500; see App\Http\Controllers\CheckoutController.
     */
    public function handle(User $user, Plan $plan, string $variant): ?string
    {
        $priceId = $this->prices->handle($user, $plan, $variant);

        if ($priceId === null) {
            return null;
        }

        /*
         * The user the auth guard hands us has no relations loaded, and the
         * lazy-loading guard is armed everywhere but production. The customer
         * row is created by the webhook rather than by checkout, so nothing
         * below reads the relation today — loading it here keeps that true of
         * the whole checkout path regardless.
         */
        $user->loadMissing('customer');

        /*
         * subscribe() rather than checkout(): it sets the `subscription_type`
         * custom key, which is what the webhook needs to record the resulting
         * subscription against this billable. Our own custom data rides
         * alongside it — billable_id, billable_type and subscription_type are
         * reserved and passing any of them here throws.
         *
         * embed() is what makes the URL openable in the Lemon.js overlay
         * instead of only as a full-page navigation.
         *
         * No redirectTo(), deliberately. Lemon Squeezy would navigate the
         * browser there the moment payment completes, which tears down the page
         * before the `Checkout.Success` handler on the pricing page can poll
         * for the entitlement — and the plan is granted by a webhook that has
         * not necessarily landed yet, so the buyer would arrive at a freshly
         * rendered page still showing their old plan. Letting the overlay close
         * on its own keeps that handler alive to do the waiting.
         */
        return $user->subscribe($priceId)
            ->withCustomData([
                'plan' => $plan->value,
                'variant' => $variant,
            ])
            ->embed()
            ->withoutLogo()
            ->url();
    }
}
