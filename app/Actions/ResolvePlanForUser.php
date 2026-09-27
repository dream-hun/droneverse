<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\Plan;
use App\Models\User;
use App\Queries\KelviqEntitlements;

final readonly class ResolvePlanForUser
{
    public function __construct(private KelviqEntitlements $entitlements)
    {
        //
    }

    /**
     * Work out which plan a user is entitled to right now.
     *
     * The branches are ordered by authority, most authoritative first:
     *
     *   1. `plan_override` — set by hand for comped, staff and academic
     *      accounts, and for pre-launch users backfilled when gating lands. It
     *      outranks Kelviq deliberately: an override exists precisely to say
     *      something billing does not know.
     *   2. Kelviq, asked whether the pilot holds the feature that stands for a
     *      tier's catalogue — `full-catalog` for Pro; see Plan::catalogFeature().
     *   3. Starter. Everyone has an account, so everyone has a plan.
     *
     * Guests resolve to Starter too, so callers sharing entitlements with an
     * unauthenticated view do not need a null branch of their own.
     *
     * Results are memorized on the User instance by App\Concerns\HasPlan, and
     * Kelviq's answer is cached by App\Queries\KelviqEntitlements, so this is
     * at most one network call per pilot per minute.
     */
    public function handle(?User $user): Plan
    {
        if (! $user instanceof User) {
            return Plan::Starter;
        }

        return $this->fromOverride($user)
            ?? $this->fromKelviq($user)
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
     * The most generous tier whose catalogue feature Kelviq grants.
     */
    private function fromKelviq(User $user): ?Plan
    {
        $granted = $this->entitlements->for($user);

        return array_find(
            array_reverse(Plan::cases()),
            static fn (Plan $plan): bool => in_array($plan->catalogFeature(), $granted, true),
        );
    }
}
