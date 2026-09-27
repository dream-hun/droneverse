<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\Plan;
use App\Http\Integrations\Kelviq;
use App\Models\User;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use RuntimeException;

/**
 * Open a Kelviq checkout for a plan, server-side.
 *
 * Returns the URL of the hosted checkout Kelviq mints. The browser is handed a
 * checkout it cannot alter the price of — it names a tier and a billing period
 * and nothing else, and what that costs is Kelviq's answer.
 */
final readonly class StartCheckout
{
    public function __construct(private Kelviq $kelviq)
    {
        //
    }

    /**
     * Returns null when the plan or period is not for sale: Starter, a period
     * the plan does not sell, or an environment with no Kelviq key.
     *
     * Throws when Kelviq will not mint a checkout. Callers reached from a
     * request must turn it into something a buyer can read rather than letting
     * it become a 500; see App\Http\Controllers\CheckoutController.
     *
     * @throws ConnectionException|RequestException|RuntimeException
     */
    public function handle(User $user, Plan $plan, string $variant): ?string
    {
        $identifier = $plan->kelviqPlan($variant);
        $chargePeriod = $plan->chargePeriod($variant);

        if (! $plan->isSelfServe() || $identifier === null || $chargePeriod === null || ! $this->kelviq->configured()) {
            return null;
        }

        return $this->kelviq->createCheckoutSession([
            'plan_identifier' => $identifier,
            'charge_period' => $chargePeriod,
            /*
             * The pilot's uuid, read from the session. It is the Kelviq
             * customerId for this account everywhere — entitlements, portal,
             * webhooks — and nothing a request carries can change it.
             */
            'customer_id' => $user->uuid,
            /*
             * Absolute, as Kelviq requires: route() builds it from APP_URL,
             * the application's own base-URL convention.
             */
            'success_url' => route('subscription.thank-you'),
            'cancel_url' => route('billing.edit'),
            /*
             * Locks the email at checkout to the one on the account, so the
             * Kelviq customer that comes back carries this pilot's address —
             * which is also what the customer portal needs before it will open.
             */
            'email' => $user->email,
            'lock_email' => true,
        ])['checkoutUrl'];
    }
}
