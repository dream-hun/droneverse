<?php

declare(strict_types=1);

namespace App\Actions;

use Carbon\CarbonImmutable;
use Carbon\Exceptions\InvalidFormatException;

/**
 * Read a timestamp out of a Creem payload.
 *
 * Creem's timestamps are ISO strings on most objects and epoch numbers on
 * transactions — milliseconds in every example, but a value too small to be
 * milliseconds after 1973 is read as seconds rather than as 1970.
 *
 * Null when the value is absent or empty, which is how Creem sends a date
 * that has not happened yet. A string that parses as neither is null as well,
 * rather than allowed to lose the rest of the payload it arrived in, and it
 * is reported: it means Creem has changed a format, which is worth hearing
 * about before every date on the billing pages has gone missing.
 */
final readonly class ParseCreemDate
{
    public function handle(mixed $value): ?CarbonImmutable
    {
        if ((is_int($value) || is_float($value)) && $value > 0) {
            return $value >= 100_000_000_000
                ? CarbonImmutable::createFromTimestampMs($value)
                : CarbonImmutable::createFromTimestamp($value);
        }

        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value);
        } catch (InvalidFormatException $invalidFormatException) {
            report($invalidFormatException);

            return null;
        }
    }
}
