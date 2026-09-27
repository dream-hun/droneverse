<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Http\Integrations\Kelviq;
use App\Http\Integrations\WebhookVerificationError;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * The check standing between a stranger and the webhook endpoint.
 *
 * Kelviq::validateEvent() over the raw body, under `KELVIQ_WEBHOOK_SECRET`, and
 * unconditional: with no secret configured nothing is accepted at all. A
 * webhook nobody can call is an outage; a webhook anybody can call is a
 * breach, and the second one is silent.
 *
 * The decoded event is handed on as the `kelviq_event` request attribute, so
 * the controller reads the body that was verified rather than parsing it a
 * second time.
 */
final readonly class VerifyKelviqWebhookSignature
{
    public function __construct(private Kelviq $kelviq)
    {
        //
    }

    /**
     * @param  Closure(Request): (Response)  $next
     *
     * @throws AccessDeniedHttpException
     */
    public function handle(Request $request, Closure $next): Response
    {
        $secret = config('kelviq.webhook_secret');

        try {
            $event = $this->kelviq->validateEvent(
                $request->getContent(),
                $request->headers->all(),
                is_string($secret) ? $secret : '',
            );
        } catch (WebhookVerificationError $webhookVerificationError) {
            throw new AccessDeniedHttpException($webhookVerificationError->getMessage(), $webhookVerificationError);
        }

        $request->attributes->set('kelviq_event', $event);

        return $next($request);
    }
}
