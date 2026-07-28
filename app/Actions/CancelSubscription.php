<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\User;
use Laravel\Paddle\Subscription;

/**
 * Cancel a pilot's subscription at the end of the period they have paid for.
 *
 * Never immediate. They bought the month; taking the catalogue away the moment
 * they click cancel is a refund conversation, and Cashier's grace period
 * already keeps `valid()` true until `ends_at` passes, so entitlements hold
 * without a special case anywhere.
 */
final readonly class CancelSubscription
{
    /**
     * Returns false when there is nothing to cancel — no subscription, or one
     * already winding down.
     */
    public function handle(User $user, Subscription $subscription): bool
    {
        if ($subscription->canceled()) {
            return false;
        }

        $subscription->cancel();

        $user->forgetPlan();

        return true;
    }
}
