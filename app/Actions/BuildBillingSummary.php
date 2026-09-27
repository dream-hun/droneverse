<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\Plan;
use App\Http\Integrations\Kelviq;
use App\Models\User;

/**
 * Everything the billing settings page renders for one pilot.
 *
 * The plan they are on and why, and — for a pilot on Starter — the upgrade to
 * Pro, priced per billing period so the page can offer the monthly/yearly
 * choice. Everything about an existing subscription (the card, invoices, the
 * billing period, cancelling) lives in Kelviq's customer portal, which the page
 * links to rather than mirrors.
 */
final readonly class BuildBillingSummary
{
    public function __construct(
        private QuotePlanPrices $prices,
        private Kelviq $kelviq,
    ) {
        //
    }

    /**
     * @return array{
     *     plan: array{value: string, label: string, isPaid: bool, source: string},
     *     upgrade: array{value: string, label: string, tagline: string, highlights: array<int, string>, prices: array<string, array{amount: int, formatted: string, savingPercent: int|null, purchasable: bool}>}|null,
     *     canManageBilling: bool,
     * }
     */
    public function handle(User $user): array
    {
        $plan = $user->plan();
        $source = $this->source($user, $plan);

        return [
            'plan' => [
                'value' => $plan->value,
                'label' => $plan->label(),
                'isPaid' => $plan->isPaid(),
                'source' => $source,
            ],
            'upgrade' => $plan->isPaid() ? null : $this->upgrade(Plan::Pro),
            /*
             * Only a pilot Kelviq bills has a portal to open. Everybody else
             * would be sent to a 400 — see BillingPortalController — so the
             * button is not offered to them.
             */
            'canManageBilling' => $source === 'kelviq',
        ];
    }

    /**
     * Why the pilot holds the plan they hold.
     *
     * `override` outranks Kelviq for the same reason it does in
     * App\Actions\ResolvePlanForUser: somebody granted it by hand.
     */
    private function source(User $user, Plan $plan): string
    {
        return match (true) {
            Plan::tryFrom($user->plan_override ?? '') instanceof Plan => 'override',
            $plan->isPaid() => 'kelviq',
            default => 'none',
        };
    }

    /**
     * @return array{value: string, label: string, tagline: string, highlights: array<int, string>, prices: array<string, array{amount: int, formatted: string, savingPercent: int|null, purchasable: bool}>}
     */
    private function upgrade(Plan $plan): array
    {
        return [
            'value' => $plan->value,
            'label' => $plan->label(),
            'tagline' => $plan->tagline(),
            'highlights' => $plan->highlights(),
            'prices' => $this->prices->handle($plan, $this->kelviq->configured()),
        ];
    }
}
