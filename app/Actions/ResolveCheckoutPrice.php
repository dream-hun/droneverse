<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\Plan;
use App\Models\User;

/**
 * The Paddle price ID a given buyer should be charged for a given plan.
 *
 * The one place a price ID is chosen, and deliberately the only one: the client
 * posts a plan and a billing period, never an ID, so no request can nominate
 * what it pays. Every discount the pricing copy promises lands here rather than
 * in a controller — Phase 4's launch pricing narrows this method, which is what
 * keeps the quoted price and the charged price the same number.
 */
final readonly class ResolveCheckoutPrice
{
    /**
     * Returns null when the plan cannot be bought — a tier that is sales-led,
     * a billing period it does not offer, or a price ID that is simply not
     * configured in this environment. Callers about to charge a card must treat
     * that as fatal rather than falling back to any other price.
     */
    public function handle(User $user, Plan $plan, string $variant): ?string
    {
        if (! $plan->isSelfServe()) {
            return null;
        }

        if (! in_array($variant, $plan->variants(), true)) {
            return null;
        }

        /*
         * Phase 4 intercepts here: while the platform is under 100 paying
         * customers — customers who have paid, not accounts that have
         * registered — Pro resolves to its `_launch` variant instead.
         */
        return $plan->priceId($variant);
    }
}
