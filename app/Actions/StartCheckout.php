<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\Plan;
use App\Models\User;

/**
 * Open a Paddle checkout for a plan, server-side.
 *
 * Returns the option bag Paddle.js expects from `Paddle.Checkout.open`, already
 * carrying the resolved price ID and the buyer's Paddle customer record. The
 * browser is handed a checkout it cannot alter the price of — it never learns
 * an ID, and it has nothing to submit but the plan it clicked.
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
     * @return array<string, mixed>|null
     */
    public function handle(User $user, Plan $plan, string $variant, string $returnTo): ?array
    {
        $priceId = $this->prices->handle($user, $plan, $variant);

        if ($priceId === null) {
            return null;
        }

        /*
         * checkout() reaches for `$user->customer` to decide whether it needs
         * to create one, and the user the auth guard hands us has no relations
         * loaded. Loading it here rather than letting Cashier touch it keeps the
         * lazy-loading guard — armed everywhere but production — out of the
         * checkout path.
         */
        $user->loadMissing('customer');

        /*
         * checkout() creates the Paddle customer on first use, so a pilot who
         * has never paid for anything gets one here rather than mid-overlay.
         */
        $options = $user->checkout($priceId)
            ->returnTo($returnTo)
            ->customData([
                'plan' => $plan->value,
                'variant' => $variant,
            ])
            ->options();

        /*
         * Cashier builds its options for the inline `<x-paddle-checkout>` Blade
         * component, which needs a frame on the page to render into. The React
         * pricing page has no such frame and wants the overlay, so the display
         * settings are replaced rather than merged — an inline frameStyle left
         * behind would silently apply to the overlay.
         */
        $options['settings'] = array_filter([
            'displayMode' => 'overlay',
            'successUrl' => $returnTo,
            'allowLogout' => false,
        ]);

        return $options;
    }
}
