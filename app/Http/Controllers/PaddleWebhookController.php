<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Laravel\Paddle\Http\Controllers\WebhookController;
use Laravel\Paddle\Http\Middleware\VerifyWebhookSignature;

/**
 * Cashier's webhook controller, with signature verification made mandatory.
 *
 * Cashier applies VerifyWebhookSignature only `if (config('cashier.webhook_secret'))`,
 * which means an environment that forgets `PADDLE_WEBHOOK_SECRET` does not fail
 * closed — it accepts any POST to the webhook endpoint, and whoever finds the
 * URL can hand themselves a subscription. This is the one endpoint in the
 * application that grants paid access without a session behind it, so the check
 * that protects it must not be conditional on the same configuration that would
 * be missing in the environment that got it wrong.
 *
 * With no secret configured, no signature can match and every call is rejected.
 * That is the correct failure: a webhook nobody can call is an outage, and a
 * webhook anybody can call is a breach.
 *
 * AppServiceProvider binds this in place of Cashier's controller, so the route,
 * its name and its handling are otherwise untouched.
 */
final class PaddleWebhookController extends WebhookController
{
    public function __construct()
    {
        $this->middleware(VerifyWebhookSignature::class);
    }
}
