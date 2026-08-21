<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\Plan;
use App\Enums\PlanChange;
use App\Http\Integrations\Creem;
use App\Models\Subscription;
use App\Models\User;

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
 * Prorated, and settled on the spot. Creem works out what the unused remainder
 * of the current period is worth and charges or refunds the difference
 * immediately — an upgrade is credited for what has already been paid, and a
 * downgrade is refunded for the time it gives up. This is a real change from
 * the Lemon Squeezy integration before it, which deferred the difference to the
 * next renewal; Creem's `proration-charge` behaved that way and is deprecated
 * to mean "immediately", so the choice is now between charging now and charging
 * nothing at all. The screens say so — the confirmation dialog on the billing
 * page and the toast after it both name the charge — because an upgrade button
 * that quietly takes money is a refund conversation.
 *
 * The alternative, `proration-none`, would hand an upgrading pilot the rest of
 * the month for free and take the remainder of a downgrading one's period away
 * unpaid for. The second half of that is the objection: it is the same money,
 * and only one party notices.
 */
final readonly class SwapSubscription
{
    /**
     * Access changes now, and the difference for the remainder of the period is
     * charged or refunded now with it.
     */
    private const string UPDATE_BEHAVIOR = 'proration-charge-immediately';

    public function __construct(
        private ResolveCheckoutPrice $prices,
        private Creem $creem,
        private SyncCreemSubscription $subscriptions,
    ) {
        //
    }

    /**
     * Throws when Creem will not make the change — no API key, a product the
     * account does not recognize, or the API answering with an error. Callers
     * reached from a request must turn that into something a pilot can read
     * rather than letting it become a 500.
     */
    public function handle(User $user, Subscription $subscription, Plan $plan, string $variant): PlanChange
    {
        $productId = $this->prices->handle($user, $plan, $variant);

        if ($productId === null) {
            return PlanChange::Unavailable;
        }

        if ($subscription->hasProduct($productId)) {
            return PlanChange::Unchanged;
        }

        $updated = $this->creem->upgradeSubscription(
            $subscription->creem_id,
            $productId,
            self::UPDATE_BEHAVIOR,
        );

        /*
         * Creem answers with the subscription as it now stands, so the row is
         * brought up to date from the response rather than from a webhook that
         * has not arrived: the pilot is redirected to the billing page in the
         * next breath, and it would otherwise render their old plan back at
         * them. `subscription.update` says the same thing again a moment later
         * and writes the same row; neither is waiting on the other.
         *
         * The fallback matters more than it looks. The sync refuses a response
         * it cannot read in full, and a response we cannot read is not a change
         * that did not happen — the card has already been charged by this
         * point. Writing the product on its own keeps the entitlement correct
         * even then, and leaves the dates to the webhook.
         */
        if (! $this->subscriptions->handle($user, $updated) instanceof Subscription) {
            $subscription->forceFill(['product_id' => $productId])->save();
        }

        $user->forgetPlan();

        return PlanChange::Swapped;
    }
}
