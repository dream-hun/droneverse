<?php

declare(strict_types=1);

namespace App\Actions;

use NumberFormatter;

/**
 * Turn an amount in minor units into money a person can read.
 *
 * Every price in this application is stored and transmitted in minor units —
 * config/plans.php quotes them, Creem reports them — because an integer number
 * of cents is the only representation of money that survives arithmetic. This
 * is the one place that turns them back into a string, so a page never divides
 * by a hundred on its way to rendering something.
 *
 * The divisor is not always a hundred, which is the reason this is not a
 * one-line helper. A yen has no minor unit at all and a dinar has three, so how
 * far to move the point is a property of the currency rather than a constant,
 * and `intl` already knows it for every currency there is.
 */
final readonly class FormatMoney
{
    /**
     * `$minFractionDigits` of 0 drops a trailing `.00`, which is what the
     * pricing page wants: "$19 / month" reads as a price and "$19.00 / month"
     * reads as an invoice. It only ever drops digits that are zero — a price of
     * $19.50 keeps them — so it cannot round anything away.
     */
    public function handle(int $minorUnits, string $currency, ?int $minFractionDigits = null): string
    {
        $currency = mb_strtoupper($currency);

        $formatter = $this->formatter($currency);

        if (! $formatter instanceof NumberFormatter) {
            return $this->fallback($minorUnits, $currency);
        }

        $exponent = $formatter->getAttribute(NumberFormatter::FRACTION_DIGITS);

        /*
         * `getAttribute` answers `false` for an attribute it cannot read, which
         * is indistinguishable from the 0 a yen legitimately has the moment PHP
         * compares it to a number. The formatter's own error code is what tells
         * the two apart.
         */
        if ($formatter->getErrorCode() !== U_ZERO_ERROR || $exponent < 0) {
            $exponent = 2;
        }

        if ($minFractionDigits !== null) {
            $formatter->setAttribute(NumberFormatter::MIN_FRACTION_DIGITS, $minFractionDigits);
        }

        $formatted = $formatter->formatCurrency($minorUnits / (10 ** $exponent), $currency);

        return is_string($formatted) ? $formatted : $this->fallback($minorUnits, $currency);
    }

    private function formatter(string $currency): ?NumberFormatter
    {
        if (! class_exists(NumberFormatter::class)) {
            return null;
        }

        $formatter = new NumberFormatter($this->locale(), NumberFormatter::CURRENCY);

        /*
         * Naming the currency on the formatter as well as on formatCurrency()
         * is what makes it answer with that currency's minor-unit exponent
         * rather than with the one belonging to the locale's own currency.
         */
        $formatter->setTextAttribute(NumberFormatter::CURRENCY_CODE, $currency);

        return $formatter;
    }

    /**
     * Two decimal places and the currency code, for an environment with no
     * `intl` extension.
     *
     * Deliberately plain. Guessing at a symbol and a thousands separator
     * without a locale library is how a price ends up saying something a
     * currency does not mean, and "USD 19.00" is understood everywhere.
     */
    private function fallback(int $minorUnits, string $currency): string
    {
        return $currency.' '.number_format($minorUnits / 100, 2);
    }

    private function locale(): string
    {
        $locale = config('creem.currency_locale');

        return is_string($locale) && $locale !== '' ? $locale : 'en';
    }
}
