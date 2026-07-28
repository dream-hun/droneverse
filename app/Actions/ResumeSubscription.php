<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\User;
use Laravel\Paddle\Subscription;

/**
 * Call off a pending cancellation.
 *
 * Only possible while the subscription is still inside the period it was paid
 * for. Once `ends_at` has passed Paddle has closed it for good, and the pilot
 * is buying a new one through checkout rather than resuming this one.
 */
final readonly class ResumeSubscription
{
    public function handle(User $user, Subscription $subscription): bool
    {
        if (! $subscription->onGracePeriod()) {
            return false;
        }

        $subscription->stopCancelation();

        $user->forgetPlan();

        return true;
    }
}
