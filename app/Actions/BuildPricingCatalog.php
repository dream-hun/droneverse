<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\Feature;
use App\Enums\Plan;
use Laravel\Paddle\Cashier;

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
    /**
     * @return array{plans: array<int, array<string, mixed>>, comparison: array<int, array<string, mixed>>, salesEmail: string|null}
     */
    public function handle(Plan $viewer, bool $isGuest): array
    {
        $salesEmail = config('plans.sales_email');

        return [
            'plans' => array_map(
                fn (Plan $plan): array => $this->card($plan, $viewer, $isGuest),
                Plan::cases(),
            ),
            'comparison' => $this->comparison(),
            'salesEmail' => is_string($salesEmail) && $salesEmail !== '' ? $salesEmail : null,
        ];
    }

    /**
     * One plan's card.
     *
     * @return array<string, mixed>
     */
    private function card(Plan $plan, Plan $viewer, bool $isGuest): array
    {
        return [
            'value' => $plan->value,
            'label' => $plan->label(),
            'tagline' => $plan->tagline(),
            'highlights' => $plan->highlights(),
            'prices' => $this->prices($plan),
            'isCurrent' => $plan === $viewer,
            /*
             * Pro carries the "most popular" ribbon in docs/pricing.md, but a
             * pilot who already holds it does not need to be sold it again.
             */
            'isPopular' => $plan === Plan::Pro && $viewer !== Plan::Pro,
            'cta' => $this->cta($plan, $viewer, $isGuest),
        ];
    }

    /**
     * The price of every variant this plan sells, keyed by variant.
     *
     * A variant with an amount but no configured Paddle price ID is still
     * quoted — the copy is true, and cta() is where the missing ID turns the
     * button off. Quoting nothing would make an unconfigured environment look
     * like a free plan.
     *
     * @return array<string, array<string, mixed>>
     */
    private function prices(Plan $plan): array
    {
        $prices = [];

        foreach ($plan->variants() as $variant) {
            $amount = $plan->amount($variant);

            if ($amount === null) {
                continue;
            }

            $prices[$variant] = [
                'amount' => $amount,
                'formatted' => Cashier::formatAmount($amount, $this->currency(), options: ['min_fraction_digits' => 0]),
                'savingPercent' => $variant === 'yearly' ? $this->annualSaving($plan) : null,
            ];
        }

        return $prices;
    }

    /**
     * How much a year up front saves against twelve monthly payments.
     *
     * Computed rather than quoted, so the "save 17%" line can never outlive the
     * prices it describes. Rounded to a whole percent, which is how it is sold.
     */
    private function annualSaving(Plan $plan): ?int
    {
        $monthly = $plan->amount('monthly');
        $yearly = $plan->amount('yearly');

        if ($monthly === null || $yearly === null || $monthly <= 0) {
            return null;
        }

        $saving = (int) round((1 - $yearly / ($monthly * 12)) * 100);

        return $saving > 0 ? $saving : null;
    }

    /**
     * What the plan's button does.
     *
     * Read top to bottom: the free tier is an account rather than a purchase,
     * a sales-led tier is never a button that charges, and everything below
     * that is about this particular viewer — what they already hold, what
     * their plan already covers, and whether the tier is on sale at all.
     *
     * @return array{action: string, label: string}
     */
    private function cta(Plan $plan, Plan $viewer, bool $isGuest): array
    {
        if (! $plan->isPaid()) {
            return match (true) {
                $isGuest => ['action' => 'signup', 'label' => 'Start free'],
                $plan === $viewer => ['action' => 'current', 'label' => 'Your current plan'],
                default => ['action' => 'included', 'label' => 'Included in your plan'],
            };
        }

        return match (true) {
            ! $plan->isSelfServe() => ['action' => 'contact', 'label' => 'Contact sales'],
            $plan === $viewer => ['action' => 'current', 'label' => 'Your current plan'],
            $viewer->covers($plan) => ['action' => 'included', 'label' => 'Included in your plan'],
            ! $this->isPurchasable($plan) => ['action' => 'unavailable', 'label' => 'Not yet available'],
            /*
             * Checkout needs a Paddle customer, and a Paddle customer needs an
             * account. Sending a guest to register rather than to a login wall
             * they would hit a moment later.
             */
            $isGuest => ['action' => 'signup', 'label' => 'Get '.$plan->label()],
            default => ['action' => 'checkout', 'label' => 'Upgrade to '.$plan->label()],
        };
    }

    /**
     * Whether checkout could actually open on this plan right now.
     *
     * A self-serve plan with no configured price ID is not for sale, however
     * confidently the card quotes a number — which is the state of every tier
     * in an environment with no Paddle catalogue behind it.
     */
    private function isPurchasable(Plan $plan): bool
    {
        foreach ($plan->variants() as $variant) {
            if ($plan->priceId($variant) !== null) {
                return true;
            }
        }

        return false;
    }

    /**
     * The capability grid, one row per feature.
     *
     * `available` is the honest column: a feature every paid tier grants but
     * nobody has built yet is marked so, rather than sold as though it ships
     * today.
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

    private function currency(): string
    {
        $currency = config('cashier.currency');

        return is_string($currency) ? $currency : 'USD';
    }
}
