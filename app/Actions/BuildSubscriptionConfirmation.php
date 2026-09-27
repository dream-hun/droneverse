<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\User;
use App\Queries\KelviqEntitlements;

/**
 * What the page a completed checkout lands on can honestly say.
 *
 * Kelviq redirects the buyer here the moment payment is taken, and grants the
 * entitlements a moment later, so the page has to survive asking the same
 * question twice. Nothing about which checkout it was travels in the URL: the
 * answer is the pilot's own entitlements, asked of Kelviq afresh every time
 * this is built, so a reload after the grant lands shows it rather than a
 * minute-old cached "no".
 */
final readonly class BuildSubscriptionConfirmation
{
    public function __construct(private KelviqEntitlements $entitlements)
    {
        //
    }

    /**
     * @return array{plan: array{value: string, label: string, isPaid: bool}, highlights: array<int, string>, pending: bool}
     */
    public function handle(User $user): array
    {
        $this->entitlements->forget($user->uuid);

        $plan = $user->forgetPlan()->plan();

        return [
            'plan' => [
                'value' => $plan->value,
                'label' => $plan->label(),
                'isPaid' => $plan->isPaid(),
            ],
            'highlights' => $plan->isPaid() ? $plan->highlights() : [],
            /*
             * Still waiting on Kelviq, which is what makes the page poll.
             */
            'pending' => ! $plan->isPaid(),
        ];
    }
}
