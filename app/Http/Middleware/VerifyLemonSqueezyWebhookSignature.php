<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Lemon Squeezy's webhook signature check, made mandatory and made total.
 *
 * The package applies its own VerifyWebhookSignature only
 * `if (config('lemon-squeezy.signing_secret'))`, which means an environment that
 * forgets `LEMON_SQUEEZY_SIGNING_SECRET` does not fail closed — it accepts any
 * POST to the webhook endpoint, and whoever finds the URL can hand themselves a
 * subscription. This is the one endpoint in the application that grants paid
 * access without a session behind it, so the check that protects it must not be
 * conditional on the same configuration that would be missing in the environment
 * that got it wrong.
 *
 * With no secret configured, nothing is accepted. That is the correct failure: a
 * webhook nobody can call is an outage, and a webhook anybody can call is a
 * breach. Note that deferring to the package's middleware would not be enough
 * even if it always ran — with an unset secret it hashes with an empty key, and
 * a forger who also hashes with an empty key matches it exactly.
 *
 * The header is checked for presence and type before it is compared, because the
 * package's middleware type-hints a `string` signature and a request that simply
 * omits `X-Signature` would raise a TypeError rather than a 403.
 *
 * Registered in routes/billing.php rather than bound in the container, because
 * LemonSqueezy\Laravel\Http\Controllers\WebhookController is final and cannot be
 * subclassed the way Cashier's could.
 */
final class VerifyLemonSqueezyWebhookSignature
{
    /**
     * @throws AccessDeniedHttpException
     */
    public function handle(Request $request, Closure $next): Response
    {
        $secret = config('lemon-squeezy.signing_secret');

        if (! is_string($secret) || $secret === '') {
            throw new AccessDeniedHttpException('Webhook signature verification is not configured.');
        }

        $signature = $request->header('X-Signature');

        if (! is_string($signature) || $signature === '') {
            throw new AccessDeniedHttpException('Missing webhook signature.');
        }

        $expected = hash_hmac('sha256', $request->getContent(), $secret);

        if (! hash_equals($expected, $signature)) {
            throw new AccessDeniedHttpException('Invalid webhook signature.');
        }

        return $next($request);
    }
}
