<?php

declare(strict_types=1);

namespace App\Concerns;

use App\Actions\ResolvePlanForUser;
use App\Enums\Feature;
use App\Enums\Plan;

/**
 * Entitlement questions, answered on the User model.
 *
 * @mixin \App\Models\User
 */
trait HasPlan
{
    /**
     * Resolution is memoised on the model instance rather than in a cache.
     *
     * A fresh User is hydrated per request, so the memo lives exactly as long
     * as it should and there is nothing to invalidate when a subscription
     * changes mid-flight. `forgetPlan()` covers the one case that needs it:
     * code that changes the user's billing state and then asks again.
     */
    private ?Plan $resolvedPlan = null;

    public function plan(): Plan
    {
        return $this->resolvedPlan ??= app(ResolvePlanForUser::class)->handle($this);
    }

    public function hasFeature(Feature $feature): bool
    {
        return $this->plan()->hasFeature($feature);
    }

    /**
     * Every feature the user's plan unlocks.
     *
     * @return array<int, Feature>
     */
    public function features(): array
    {
        return $this->plan()->features();
    }

    public function onPaidPlan(): bool
    {
        return $this->plan()->isPaid();
    }

    /**
     * Whether the user may reach content gated behind `$required`.
     */
    public function planCovers(Plan $required): bool
    {
        return $this->plan()->covers($required);
    }

    /**
     * Drop the memoised plan so the next call resolves from scratch.
     */
    public function forgetPlan(): static
    {
        $this->resolvedPlan = null;

        return $this;
    }
}
