<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\Plan;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Laravel\Paddle\Subscription;
use Laravel\Paddle\SubscriptionItem;

final class ResolvePlanForUser
{
    /**
     * Work out which plan a user is entitled to right now.
     *
     * The branches are ordered by authority, most authoritative first:
     *
     *   1. `plan_override` — set by hand for comped, staff and academic
     *      accounts, and for pre-launch users backfilled when gating lands. It
     *      outranks Paddle deliberately: an override exists precisely to say
     *      something billing does not know.
     *   2. An active Paddle subscription, mapped back to a plan through the
     *      price ID in config/plans.php.
     *   3. Starter. Everyone has an account, so everyone has a plan.
     *
     * Guests resolve to Starter too, so callers sharing entitlements with an
     * unauthenticated view do not need a null branch of their own.
     *
     * Results are memoised on the User instance by App\Concerns\HasPlan, which
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
     * personal Pro seat alongside a Team one — so every price ID is considered
     * and the most generous wins.
     */
    private function fromSubscription(User $user): ?Plan
    {
        $priceIds = SubscriptionItem::query()
            ->whereIn('subscription_id', $this->validSubscriptionIds($user))
            ->pluck('price_id')
            ->all();

        $plans = [];

        foreach ($priceIds as $priceId) {
            $plan = Plan::fromPriceId(is_string($priceId) ? $priceId : null);

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
     * IDs of the user's subscriptions Cashier still considers valid — active or
     * trialing, plus past due unless Cashier is set to deactivate it.
     *
     * Querying Subscription directly rather than reading `$user->subscriptions`
     * keeps resolution clear of the lazy-loading guard, which is armed
     * everywhere but production.
     *
     * @return Builder<Subscription>
     */
    private function validSubscriptionIds(User $user): Builder
    {
        return Subscription::query()
            ->whereMorphedTo('billable', $user)
            ->valid()
            ->select('id');
    }
}
