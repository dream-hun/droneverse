<?php

declare(strict_types=1);

namespace App\Concerns;

use App\Actions\ResolveFeaturesForUser;
use App\Actions\ResolvePlanForUser;
use App\Enums\Feature;
use App\Enums\Plan;
use App\Models\User;
use App\Queries\KelviqEntitlements;

/**
 * Entitlement questions, answered on the User model.
 *
 * @mixin User
 */
trait HasPlan
{
    /**
     * Resolution is memoised on the model instance rather than in a cache.
     *
     * A fresh User is hydrated per request, so the memo lives exactly as long
     * as it should and there is nothing to invalidate when a subscription
     * changes mid-flight. `forgetPlan()` covers the one case that needs it:
     * code that learns the user's billing state has changed and asks again.
     */
    private ?Plan $resolvedPlan = null;

    /** @var array<int, Feature>|null */
    private ?array $resolvedFeatures = null;

    /** @var array<int, string>|null */
    private ?array $resolvedEntitlements = null;

    public function plan(): Plan
    {
        return $this->resolvedPlan ??= resolve(ResolvePlanForUser::class)->handle($this);
    }

    /**
     * Asked of the feature itself rather than of the plan: a Kelviq subscriber
     * holds what Kelviq's entitlements grant, feature by feature. See
     * App\Actions\ResolveFeaturesForUser.
     */
    public function hasFeature(Feature $feature): bool
    {
        return in_array($feature, $this->features(), true);
    }

    /**
     * Every feature the user may use right now.
     *
     * @return array<int, Feature>
     */
    public function features(): array
    {
        return $this->resolvedFeatures ??= resolve(ResolveFeaturesForUser::class)->handle($this);
    }

    /**
     * The Kelviq feature identifiers the user holds, read once per instance.
     *
     * Both plan() and features() are derived from this one answer, and every
     * signed-in page asks both — the shared Inertia props carry the plan and
     * the feature list. Read separately, that was the same cache entry fetched
     * twice on every request, and with the database cache store each fetch is
     * a query. Memoised here, beside the two answers built from it, so that
     * `forgetPlan()` retires all three together.
     *
     * @return array<int, string>
     */
    public function kelviqEntitlements(): array
    {
        return $this->resolvedEntitlements ??= resolve(KelviqEntitlements::class)->for($this);
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
        $this->resolvedFeatures = null;
        $this->resolvedEntitlements = null;

        return $this;
    }
}
