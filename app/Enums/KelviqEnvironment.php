<?php

declare(strict_types=1);

namespace App\Enums;

use InvalidArgumentException;

/**
 * Which of Kelviq's two entirely separate worlds this environment bills in.
 *
 * Sandbox and production are different hosts with their own catalog, their
 * own customers and their own keys, and a key from one is simply unknown to
 * the other. The host is therefore chosen from `KELVIQ_ENV`, the convention the
 * Kelviq CLI and SDKs share, rather than configured beside it.
 */
enum KelviqEnvironment: string
{
    case Sandbox = 'sandbox';
    case Production = 'production';

    /**
     * The port of the SDK's `environmentFromEnv()`.
     *
     * Unset or blank is sandbox, deliberately: an environment nobody has
     * configured should be unable to take real money. Anything else that is
     * not one of the two names is refused rather than guessed at — a typo in
     * `KELVIQ_ENV` must not quietly decide which world a card is charged in.
     *
     * @throws InvalidArgumentException
     */
    public static function fromEnv(mixed $value): self
    {
        $normalized = is_string($value) ? mb_trim($value) : '';

        if ($normalized === '') {
            return self::Sandbox;
        }

        return self::tryFrom($normalized)
            ?? throw new InvalidArgumentException(sprintf('Invalid KELVIQ_ENV value "%s": expected "production" or "sandbox".', $normalized));
    }

    /**
     * The host every catalog, checkout, portal and subscription call goes to.
     */
    public function apiUrl(): string
    {
        return match ($this) {
            self::Sandbox => 'https://sandboxapi.kelviq.com/api/v1',
            self::Production => 'https://api.kelviq.com/api/v1',
        };
    }

    /**
     * The host entitlement reads go to.
     *
     * A separate edge service in both worlds, which is what lets the check run
     * on every request without queueing behind the rest of the API.
     */
    public function edgeApiUrl(): string
    {
        return match ($this) {
            self::Sandbox => 'https://edge.sandboxapi.kelviq.com/api/v1',
            self::Production => 'https://edge.api.kelviq.com/api/v1',
        };
    }
}
