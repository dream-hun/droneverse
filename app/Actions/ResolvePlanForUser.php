<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\Plan;
use App\Models\Subscription;
use App\Models\User;

final readonly class ResolvePlanForUser
{
    /**
     * Work out which plan a user is entitled to right now.
     *
     * The branches are ordered by authority, most authoritative first:
     *
     *   1. `plan_override` — set by hand for comped, staff and academic
     *      accounts, and for pre-launch users backfilled when gating lands. It
     *      outranks Creem deliberately: an override exists precisely to say
     *      something billing does not know.
     *   2. An active Creem subscription, mapped back to a plan through the
     *      product ID in config/plans.php.
     *   3. Starter. Everyone has an account, so everyone has a plan.
     *
     * Guests resolve to Starter too, so callers sharing entitlements with an
     * unauthenticated view do not need a null branch of their own.
     *
     * Results are memorized on the User instance by App\Concerns\HasPlan, which
     * makes this per request without a cache to invalidate.
     */
    public function handle(?User $user): Plan
    {
        if (! $user instanceof User) {
            return Plan::Starter;
        }

        /*
         * Phase 7 adds a fromTeamMembership() branch between the subscription
         * and the fallback: a student on an instructor's seat is entitled to
         * Team without holding a subscription of their own.
         */
        return $this->fromOverride($user)
            ?? $this->fromSubscription($user)
            ?? Plan::Starter;
    }

    /**
     * A manually assigned plan, if the stored value still names a real plan.
     *
     * A value that no longer maps to a case — a plan we renamed or retired —
     * falls through to the next branch rather than throwing. Resolution runs on
     * every request; a stale override should cost the user their upgrade, not
     * the whole page.
     */
    private function fromOverride(User $user): ?Plan
    {
        return Plan::tryFrom($user->plan_override ?? '');
    }

    /**
     * The best plan among the price IDs the user currently subscribes to.
     *
     * A user can hold more than one valid subscription — mid-upgrade, or a
     * personal Pro seat alongside a Team one — so every product ID is
     * considered and the most generous wins.
     */
    private function fromSubscription(User $user): ?Plan
    {
        $plans = [];

        foreach ($this->validPriceIds($user) as $priceId) {
            $plan = Plan::fromPriceId($priceId);

            if ($plan instanceof Plan) {
                $plans[] = $plan;
            }
        }

        return array_reduce(
            $plans,
            static fn (?Plan $best, Plan $plan): Plan => $best instanceof Plan && $best->covers($plan) ? $best : $plan,
        );
    }

    /**
     * The product IDs behind the user's still-valid subscriptions — active or
     * trialing, plus past due and the grace period after a cancellation.
     *
     * A Creem subscription names the product it sells on the row itself, so
     * there is no join here: a Creem product carries its own price and billing
     * period, and Paddle's subscription_items table has no counterpart in this
     * schema.
     *
     * Filtered in PHP rather than in SQL because which statuses entitle
     * anything is App\Enums\SubscriptionStatus's answer, and re-expressing it as
     * a `whereIn` here would leave two definitions of "valid" to drift apart.
     * The set is one user's subscriptions, so it is small enough that the
     * difference does not matter.
     *
     * Querying Subscription directly rather than reading `$user->subscriptions`
     * keeps resolution clear of the lazy-loading guard, which is armed
     * everywhere but production. A caller that has already eager loaded the
     * relation — the admin user list, resolving a page of plans at once — is
     * read from instead, which is no lazy load at all and saves a query per
     * row.
     *
     * @return array<int, string>
     */
    private function validPriceIds(User $user): array
    {
        $subscriptions = $user->relationLoaded('subscriptions')
            ? $user->subscriptions
            : Subscription::query()->whereMorphedTo('billable', $user)->get();

        return $subscriptions
            ->filter(fn (Subscription $subscription): bool => $subscription->valid())
            ->map(fn (Subscription $subscription): string => $subscription->product_id)
            ->values()
            ->all();
    }
}
