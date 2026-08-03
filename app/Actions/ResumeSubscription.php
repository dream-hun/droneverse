<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\User;
use LemonSqueezy\Laravel\Subscription;

/**
 * Call off a pending cancellation.
 *
 * Only possible while the subscription is still inside the period it was paid
 * for. Once `ends_at` has passed Lemon Squeezy has expired it for good, and the
 * pilot is buying a new one through checkout rather than resuming this one.
 *
 * The grace-period guard earns its keep twice over: it is the rule above, and
 * it is also what keeps `resume()`'s LogicException on an expired subscription
 * out of the request. An expired subscription is a false here, not a 500.
 */
final readonly class ResumeSubscription
{
    public function handle(User $user, Subscription $subscription): bool
    {
        if (! $subscription->onGracePeriod()) {
            return false;
        }

        $subscription->resume();

        $user->forgetPlan();

        return true;
    }
}
