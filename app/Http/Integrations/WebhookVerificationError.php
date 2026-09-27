<?php

declare(strict_types=1);

namespace App\Http\Integrations;

use RuntimeException;

/**
 * A webhook delivery whose signature does not prove it came from Kelviq.
 *
 * The port of the Node SDK's `WebhookVerificationError`: thrown by
 * Kelviq::validateEvent() for a missing header, a malformed signature, a
 * timestamp outside the tolerance, or a digest that does not match.
 */
final class WebhookVerificationError extends RuntimeException
{
    //
}
