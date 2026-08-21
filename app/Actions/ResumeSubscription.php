<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\SubscriptionStatus;
use App\Http\Integrations\Creem;
use App\Models\Subscription;
use App\Models\User;

/**
 * Call off a pending cancellation.
 *
 * Only possible while the subscription is still inside the period it was paid
 * for. Once that period has passed Creem has cancelled it for good, and the
 * pilot is buying a new one through checkout rather than resuming this one.
 *
 * The grace-period guard earns its keep twice over: it is the rule above, and
 * it is also what keeps Creem's refusal out of the request. The resume endpoint
 * accepts only a subscription in `scheduled_cancel` or `paused`, and an expired
 * one is a false here rather than an exception a pilot has to read.
 */
final readonly class ResumeSubscription
{
    public function __construct(
        private Creem $creem,
        private SyncCreemSubscription $subscriptions,
    ) {
        //
    }

    public function handle(User $user, Subscription $subscription): bool
    {
        if (! $subscription->onGracePeriod()) {
            return false;
        }

        $resumed = $this->creem->resumeSubscription($subscription->creem_id);

        if (! $this->subscriptions->handle($user, $resumed) instanceof Subscription) {
            $subscription->forceFill([
                'status' => SubscriptionStatus::Active->value,
                'canceled_at' => null,
            ])->save();
        }

        $user->forgetPlan();

        return true;
    }
}
