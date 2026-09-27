<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\Feature;
use App\Enums\Plan;

/**
 * Everything the pricing page renders, resolved for one viewer.
 *
 * The page is a view onto App\Enums\Plan and config/plans.php and holds no plan
 * knowledge of its own — which tier is buyable, which is already yours, and
 * which capability has actually shipped are all answered here, where they can
 * be tested without a browser.
 */
final readonly class BuildPricingCatalog
{
    public function __construct(private QuotePlanPrices $prices)
    {
        //
    }

    /**
     * `$purchasable` is whether a checkout can be opened in this environment at
     * all — false wherever no Kelviq key is configured.
     *
     * @return array{plans: array<int, array<string, mixed>>, comparison: array<int, array<string, mixed>>}
     */
    public function handle(Plan $viewer, bool $isGuest, bool $purchasable): array
    {
        return [
            'plans' => array_map(
                fn (Plan $plan): array => $this->card($plan, $viewer, $isGuest, $purchasable),
                Plan::cases(),
            ),
            'comparison' => $this->comparison(),
        ];
    }

    /**
     * One plan's card.
     *
     * @return array<string, mixed>
     */
    private function card(Plan $plan, Plan $viewer, bool $isGuest, bool $purchasable): array
    {
        return [
            'value' => $plan->value,
            'label' => $plan->label(),
            'tagline' => $plan->tagline(),
            'highlights' => $plan->highlights(),
            'prices' => $this->prices->handle($plan, $purchasable),
            /*
             * A visitor with no account holds no plan, so there is nothing to
             * badge as the one they are on. `$viewer` falls back to Starter for
             * a guest precisely so callers need no null branch, and reading that
             * fallback as a plan they hold would put "Current" on the same card
             * as "Start free".
             */
            'isCurrent' => ! $isGuest && $plan === $viewer,
            'isPopular' => $plan === Plan::Pro && $viewer !== Plan::Pro,
            'cta' => $this->cta($plan, $viewer, $isGuest, $purchasable),
        ];
    }

    /**
     * What the plan's button does.
     *
     * Read top to bottom: the free tier is an account rather than a purchase,
     * and everything below that is about this particular viewer — what they
     * already hold, whether the tier is on sale at all, and whether they have
     * an account to buy it with.
     *
     * A subscriber's way back down to free is cancelling in the Kelviq portal,
     * not a button here: there is no Starter price to move onto, and they keep
     * what they paid for until the period runs out.
     *
     * @return array{action: string, label: string}
     */
    private function cta(Plan $plan, Plan $viewer, bool $isGuest, bool $purchasable): array
    {
        if (! $plan->isPaid()) {
            return match (true) {
                $isGuest => ['action' => 'signup', 'label' => 'Start free'],
                $plan === $viewer => ['action' => 'current', 'label' => 'Your current plan'],
                default => ['action' => 'included', 'label' => 'Included in your plan'],
            };
        }

        return match (true) {
            $plan === $viewer => ['action' => 'current', 'label' => 'Your current plan'],
            ! $purchasable => ['action' => 'unavailable', 'label' => 'Not yet available'],
            /*
             * Checkout is minted for a Kelviq customer, and the customer is an
             * account. Sending a guest to register rather than to a login wall
             * they would hit a moment later.
             */
            $isGuest => ['action' => 'signup', 'label' => 'Get '.$plan->label()],
            default => ['action' => 'checkout', 'label' => 'Upgrade to '.$plan->label()],
        };
    }

    /**
     * The capability grid, one row per feature.
     *
     * `available` is the honest column: a feature a paid tier grants but nobody
     * has built yet is marked so, rather than sold as though it ships today.
     *
     * @return array<int, array<string, mixed>>
     */
    private function comparison(): array
    {
        return array_map(static fn (Feature $feature): array => [
            'value' => $feature->value,
            'label' => $feature->label(),
            'available' => $feature->isAvailable(),
            'plans' => array_map(
                static fn (Plan $plan): bool => $plan->hasFeature($feature),
                Plan::cases(),
            ),
        ], Feature::cases());
    }
}
