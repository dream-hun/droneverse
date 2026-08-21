<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\Plan;
use App\Http\Integrations\Creem;
use App\Models\User;
use RuntimeException;

/**
 * Open a Creem checkout for a plan, server-side.
 *
 * Returns the checkout URL Creem mints for the resolved product. The browser is
 * handed a checkout it cannot alter the price of — it never learns a product
 * ID, and it has nothing to submit but the plan it clicked. The URL is already
 * scoped to this buyer and this product by the time it leaves here.
 */
final readonly class StartCheckout
{
    public function __construct(
        private ResolveCheckoutPrice $prices,
        private Creem $creem,
    ) {
        //
    }

    /**
     * Returns null when the plan is not for sale in this environment; see
     * ResolveCheckoutPrice for what that covers.
     *
     * Throws when Creem will not mint a checkout — no API key, a product the
     * account does not have, or the API answering with an error. Callers
     * reached from a request must turn it into something a buyer can read
     * rather than letting it become a 500; see
     * App\Http\Controllers\CheckoutController.
     */
    public function handle(User $user, Plan $plan, string $variant): ?string
    {
        $productId = $this->prices->handle($user, $plan, $variant);

        if ($productId === null) {
            return null;
        }

        $checkout = $this->creem->createCheckout([
            'product_id' => $productId,
            /*
             * Where a buyer lands if the browser navigates rather than framing
             * the checkout — the embed script being blocked, or a URL opened
             * directly. The embed cancels this redirect when the page closes
             * the overlay itself, so setting it costs the inline flow nothing
             * and rescues the fallback one. The Lemon Squeezy integration this
             * replaces could not have both: its overlay would navigate away the
             * instant payment landed, so it set no redirect at all and left a
             * script-blocked buyer stranded on the payment page.
             */
            'success_url' => route('subscription.thank-you'),
            /*
             * Locks the email at checkout to the one on the account, so the
             * Creem customer that comes back is this pilot rather than whoever
             * they happened to type. It is also what lets a payment made
             * without metadata still be placed — see ResolveCreemBillable.
             */
            'customer' => ['email' => $user->email],
            /*
             * Creem copies this onto the subscription it creates and onto every
             * event about it afterwards, which is how a webhook arriving hours
             * later knows whose plan to grant. `plan` and `variant` are not read
             * by anything — the product ID on the subscription is what decides
             * entitlements — and are here so that a support conversation about
             * one payment can be had in this application's own vocabulary.
             */
            'metadata' => [
                'billable_id' => (string) $user->getKey(),
                'billable_type' => $user->getMorphClass(),
                'plan' => $plan->value,
                'variant' => $variant,
            ],
        ]);

        $url = $checkout['checkout_url'] ?? null;

        throw_unless(is_string($url) && $url !== '', RuntimeException::class, 'Creem returned no checkout URL.');

        return $url;
    }
}
