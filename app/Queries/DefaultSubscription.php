<?php

declare(strict_types=1);

namespace App\Queries;

use App\Models\Subscription;
use App\Models\User;

/**
 * The one subscription a pilot's billing is about.
 *
 * A billable may hold several rows — an expired one beside the live one, and a
 * second one bought after the first lapsed — so "their subscription" is a
 * question with an answer worth writing down once. It is the newest of the
 * default type, and every screen that acts on a subscription acts on this one.
 *
 * Queried rather than read off `$user->subscriptions`, which is what makes this
 * a query object rather than a method on the model: the relation is unloaded on
 * a freshly authenticated user and the lazy-loading guard is armed everywhere
 * but production, so reading it would throw on the very pages that need it.
 *
 * Scoping by the authenticated user and nothing else is also the whole of the
 * authorization story for cancelling, resuming and switching plans. There is no
 * subscription identifier in any of those requests, so there is nothing for one
 * pilot to substitute for another's.
 */
final readonly class DefaultSubscription
{
    /**
     * Null for a guest, and for an account that has never subscribed.
     */
    public function for(?User $user): ?Subscription
    {
        if (! $user instanceof User) {
            return null;
        }

        return Subscription::query()
            ->whereMorphedTo('billable', $user)
            ->where('type', Subscription::DEFAULT_TYPE)
            ->latest('id')
            ->first();
    }

    /**
     * Whether that subscription may be moved onto another plan.
     *
     * Two conditions, and the second is not obvious. A subscription has to be
     * valid to be worth changing at all. It also has to not be winding down: a
     * cancelled subscription stays valid through its grace period, but repricing
     * something already scheduled to end takes money for a plan the pilot has
     * said they do not want. They resume first, then switch.
     *
     * A predicate over a subscription rather than a second lookup by user, so a
     * caller that needs both the row and this answer — the pricing page needs
     * the difference between "no subscription" and "one that is ending" — pays
     * for one query.
     */
    public function isSwitchable(?Subscription $subscription): bool
    {
        return $subscription instanceof Subscription
            && $subscription->valid()
            && ! $subscription->cancelled();
    }
}
