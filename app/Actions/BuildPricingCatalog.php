<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\Feature;
use App\Enums\Plan;
use LemonSqueezy\Laravel\LemonSqueezy;

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
     * The last two arguments are both about the viewer's subscription, and they
     * differ for exactly one pilot.
     *
     * `$hasSubscription` says a live subscription exists, which is what stops
     * this page selling a second one beside it. `$canSwitch` says that
     * subscription may be moved onto another plan, which is what most of the
     * buttons here become for a subscriber. The pilot they disagree about is the
     * one who has cancelled and is running out their grace period: nothing to
     * sell them, and nothing to move until they resume. See
     * App\Queries\DefaultSubscription.
     *
     * @return array{plans: array<int, array<string, mixed>>, comparison: array<int, array<string, mixed>>, salesEmail: string|null}
     */
    public function handle(Plan $viewer, bool $isGuest, bool $hasSubscription = false, bool $canSwitch = false): array
    {
        $salesEmail = config('plans.sales_email');

        return [
            'plans' => array_map(
                fn (Plan $plan): array => $this->card($plan, $viewer, $isGuest, $hasSubscription, $canSwitch),
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
    private function card(Plan $plan, Plan $viewer, bool $isGuest, bool $hasSubscription, bool $canSwitch): array
    {
        return [
            'value' => $plan->value,
            'label' => $plan->label(),
            'tagline' => $plan->tagline(),
            'highlights' => $plan->highlights(),
            'prices' => $this->prices($plan),
            /*
             * A visitor with no account holds no plan, so there is nothing to
             * badge as the one they are on. `$viewer` falls back to Starter for
             * a guest precisely so callers need no null branch, and reading that
             * fallback as a plan they hold would put "Current" on the same card
             * as "Start free".
             */
            'isCurrent' => ! $isGuest && $plan === $viewer,
            /*
             * Pro carries the "most popular" ribbon in docs/pricing.md, but a
             * pilot who already holds it does not need to be sold it again.
             */
            'isPopular' => $plan === Plan::Pro && $viewer !== Plan::Pro,
            'cta' => $this->cta($plan, $viewer, $isGuest, $hasSubscription, $canSwitch),
        ];
    }

    /**
     * The price of every variant this plan sells, keyed by variant.
     *
     * A variant with an amount but no configured Lemon Squeezy variant ID is still
     * quoted — the copy is true, and `purchasable` is what turns the button off.
     * Quoting nothing would make an unconfigured environment look like a free
     * plan.
     *
     * `purchasable` is per variant because that is the granularity the answer
     * actually has: a store is built one variant at a time, and a tier with a
     * monthly ID and no yearly one sells one of its two periods. cta() below
     * cannot express that — it is computed once for the card, while the period
     * is chosen afterwards by a toggle that never asks the server again — so a
     * plan-wide answer there would leave the page showing a working upgrade
     * button over a period no checkout can be opened on.
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
                'formatted' => LemonSqueezy::formatAmount($amount, $this->currency(), options: ['min_fraction_digits' => 0]),
                'savingPercent' => $variant === 'yearly' ? $this->annualSaving($plan) : null,
                /*
                 * Whether ResolveCheckoutPrice would answer with an ID for this
                 * plan and period — the same two questions it asks, in the same
                 * order, so the button and the endpoint behind it cannot
                 * disagree. Still presentation only: the endpoint runs this
                 * check itself whatever the browser believes.
                 */
                'purchasable' => $plan->isSelfServe() && $plan->priceId($variant) !== null,
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
     * that is about this particular viewer — what they already hold, whether
     * they hold it through a subscription that can be moved, what their plan
     * already covers, and whether the tier is on sale at all.
     *
     * @return array{action: string, label: string}
     */
    private function cta(Plan $plan, Plan $viewer, bool $isGuest, bool $hasSubscription, bool $canSwitch): array
    {
        if (! $plan->isPaid()) {
            return match (true) {
                $isGuest => ['action' => 'signup', 'label' => 'Start free'],
                $plan === $viewer => ['action' => 'current', 'label' => 'Your current plan'],
                /*
                 * A subscriber's way back down to free is cancelling, not a
                 * swap: there is no Starter price to move a subscription onto,
                 * and they keep what they paid for until the period runs out.
                 * That button lives on the billing page.
                 */
                default => ['action' => 'included', 'label' => 'Included in your plan'],
            };
        }

        return match (true) {
            ! $plan->isSelfServe() => ['action' => 'contact', 'label' => 'Contact sales'],
            $plan === $viewer => ['action' => 'current', 'label' => 'Your current plan'],
            /*
             * A subscription that is cancelled but still inside the period it
             * was paid for can be moved nowhere, and must not be sold a second
             * subscription to sit beside it. Resuming is the one thing that
             * unblocks either, and it lives on the billing page.
             */
            $hasSubscription && ! $canSwitch => ['action' => 'manage', 'label' => 'Manage subscription'],
            ! $this->isPurchasable($plan) => ['action' => 'unavailable', 'label' => 'Not yet available'],
            /*
             * A subscriber changes what their one subscription sells. Offered
             * downhill as well as up — a Team pilot who wants Pro is switching,
             * not being told they already have it — which is why this outranks
             * the `covers()` branch below.
             */
            $canSwitch => [
                'action' => 'switch',
                'label' => ($viewer->covers($plan) ? 'Switch to ' : 'Upgrade to ').$plan->label(),
            ],
            $viewer->covers($plan) => ['action' => 'included', 'label' => 'Included in your plan'],
            /*
             * Checkout is minted against a billable, and a billable needs an
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
     * in an environment with no Lemon Squeezy store behind it.
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

    /**
     * The currency the quoted amounts are denominated in.
     *
     * Read from config/plans.php rather than from the provider's config,
     * because it describes this page's copy: it is the currency the numbers in
     * `plans.amounts` are written in, and Lemon Squeezy is free to charge the
     * buyer in another one at checkout.
     */
    private function currency(): string
    {
        $currency = config('plans.currency');

        return is_string($currency) ? $currency : 'USD';
    }
}
