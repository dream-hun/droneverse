<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\Feature;
use App\Enums\Plan;
use App\Models\User;
use App\Queries\KelviqEntitlements;

final readonly class ResolveFeaturesForUser
{
    public function __construct(private KelviqEntitlements $entitlements)
    {
        //
    }

    /**
     * Every capability a user may use right now.
     *
     * The same authority order as App\Actions\ResolvePlanForUser, answered per
     * feature rather than per tier:
     *
     *   1. `plan_override` grants everything the named plan lists in
     *      Plan::features(). Staff and comped accounts are not Kelviq
     *      customers, and asking Kelviq about them would answer "nothing".
     *   2. Otherwise Kelviq decides each feature by its own entitlement, so a
     *      plan reshaped in Kelviq changes what a subscriber has without a
     *      deploy here.
     *
     * Guests have nothing.
     *
     * @return array<int, Feature>
     */
    public function handle(?User $user): array
    {
        if (! $user instanceof User) {
            return [];
        }

        $override = Plan::tryFrom($user->plan_override ?? '');

        if ($override instanceof Plan) {
            return $override->features();
        }

        $granted = $this->entitlements->for($user);

        return array_values(array_filter(
            Feature::cases(),
            static fn (Feature $feature): bool => in_array($feature->kelviqId(), $granted, true),
        ));
    }
}
