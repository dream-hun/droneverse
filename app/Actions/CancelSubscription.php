<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\SubscriptionStatus;
use App\Http\Integrations\Creem;
use App\Models\Subscription;
use App\Models\User;

/**
 * Cancel a pilot's subscription at the end of the period they have paid for.
 *
 * Never immediate, which is a choice this Action makes rather than a default it
 * accepts: Creem's cancel endpoint cuts access off on the spot unless it is
 * told otherwise, and `scheduled` is what tells it otherwise. The pilot bought
 * the month; taking the catalogue away the moment they click cancel is a refund
 * conversation, and a subscription in Creem's `scheduled_cancel` status still
 * entitles everything it sold until its period ends, so nothing anywhere needs
 * a special case for it.
 */
final readonly class CancelSubscription
{
    public function __construct(
        private Creem $creem,
        private SyncCreemSubscription $subscriptions,
    ) {
        //
    }

    /**
     * Returns false when there is nothing to cancel — no subscription, or one
     * already winding down.
     */
    public function handle(User $user, Subscription $subscription): bool
    {
        if ($subscription->cancelled()) {
            return false;
        }

        $cancelled = $this->creem->cancelSubscription($subscription->creem_id, 'scheduled');

        /*
         * Applied locally from Creem's own answer so the billing page the pilot
         * is redirected to shows the cancellation rather than the state before
         * it. `subscription.scheduled_cancel` follows and writes the same row.
         *
         * The fallback is the status alone, for a response this cannot read in
         * full: the cancellation has already happened at Creem, and a page that
         * still offers a cancel button for it would be the worst of both.
         */
        if (! $this->subscriptions->handle($user, $cancelled) instanceof Subscription) {
            $subscription->forceFill(['status' => SubscriptionStatus::ScheduledCancel->value])->save();
        }

        $user->forgetPlan();

        return true;
    }
}
