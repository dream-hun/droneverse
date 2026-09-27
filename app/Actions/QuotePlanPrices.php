<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\Plan;

/**
 * What each billing period of a plan costs, as the pages that sell it quote it.
 *
 * Shared by the pricing page and the upgrade card in billing settings, so the
 * two cannot quote the same plan differently.
 */
final readonly class QuotePlanPrices
{
    public function __construct(private FormatMoney $money)
    {
        //
    }

    /**
     * The price of every variant this plan sells, keyed by variant.
     *
     * `$purchasable` is whether a checkout can be opened on the plan at all in
     * this environment. A variant is quoted either way — the copy is true — and
     * the flag is what turns the button off. Quoting nothing would make an
     * unconfigured environment look like a free plan.
     *
     * @return array<string, array{amount: int, formatted: string, savingPercent: int|null, purchasable: bool}>
     */
    public function handle(Plan $plan, bool $purchasable): array
    {
        $prices = [];

        foreach ($plan->variants() as $variant) {
            $amount = $plan->amount($variant);

            if ($amount === null) {
                continue;
            }

            $prices[$variant] = [
                'amount' => $amount,
                'formatted' => $this->money->handle($amount, $this->currency(), minFractionDigits: 0),
                'savingPercent' => $variant === 'yearly' ? $this->annualSaving($plan) : null,
                'purchasable' => $purchasable && $plan->isSelfServe() && $plan->chargePeriod($variant) !== null,
            ];
        }

        return $prices;
    }

    /**
     * How much cheaper a year is than twelve months, as a whole percentage.
     *
     * Null when either price is missing, or when a year saves nothing.
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

    private function currency(): string
    {
        $currency = config('plans.currency');

        return is_string($currency) ? $currency : 'USD';
    }
}
