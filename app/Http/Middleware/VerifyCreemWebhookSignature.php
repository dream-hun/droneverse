<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * The only thing standing between a stranger and a free Pro subscription.
 *
 * Creem signs every webhook body with HMAC-SHA256 under the webhook secret and
 * sends the hex digest in `creem-signature`. This is that check, and it is
 * unconditional: with no secret configured nothing is accepted at all.
 *
 * That is the correct failure, and it is worth being explicit about why. This
 * is the one endpoint in the application that grants paid access with no
 * session behind it, so the check protecting it must not be conditional on the
 * same configuration that would be missing in the environment that got it
 * wrong. A webhook nobody can call is an outage; a webhook anybody can call is
 * a breach, and the second one is silent.
 *
 * Hashing with an empty key would be worse than useless — a forger hashing with
 * an empty key matches it exactly — which is why the secret is checked for
 * presence rather than merely read.
 *
 * The header is checked for presence and type before it is compared, so a
 * request that simply omits it is a 403 rather than a TypeError, and the
 * comparison itself is `hash_equals` so a wrong signature takes the same time
 * to reject however wrong it is.
 */
final class VerifyCreemWebhookSignature
{
    /**
     * @throws AccessDeniedHttpException
     */
    public function handle(Request $request, Closure $next): Response
    {
        $secret = config('creem.webhook_secret');

        throw_if(! is_string($secret) || $secret === '', AccessDeniedHttpException::class, 'Webhook signature verification is not configured.');

        $signature = $request->header('creem-signature');

        throw_if(! is_string($signature) || $signature === '', AccessDeniedHttpException::class, 'Missing webhook signature.');

        $expected = hash_hmac('sha256', $request->getContent(), $secret);

        throw_unless(hash_equals($expected, $signature), AccessDeniedHttpException::class, 'Invalid webhook signature.');

        return $next($request);
    }
}
