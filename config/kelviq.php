<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Server API Key
    |--------------------------------------------------------------------------
    |
    | Sent as a bearer token on every request. Copied from Settings in the
    | Kelviq dashboard, and different in sandbox and production — see
    | `environment` below for which of the two this one belongs to.
    |
    | Server-side only. The browser is only ever handed the checkout and portal
    | URLs Kelviq mints; nothing about an entitlement is decided client-side.
    |
    */

    'server_api_key' => env('KELVIQ_SERVER_API_KEY'),

    /*
    |--------------------------------------------------------------------------
    | Environment
    |--------------------------------------------------------------------------
    |
    | `sandbox` or `production`, read by App\Enums\KelviqEnvironment::fromEnv().
    | Unset means sandbox, so an environment nobody configured cannot charge a
    | real card. Going live is setting this to `production` beside production
    | keys in the hosting settings — never in a committed file.
    |
    */

    'environment' => env('KELVIQ_ENV'),

    /*
    |--------------------------------------------------------------------------
    | Webhook Secret
    |--------------------------------------------------------------------------
    |
    | The signing secret of the endpoint registered with
    | `npx kelviq webhook add`, which writes it into the env file without
    | printing it. App\Http\Middleware\VerifyKelviqWebhookSignature refuses
    | every delivery while this is unset.
    |
    */

    'webhook_secret' => env('KELVIQ_WEBHOOK_SECRET'),

    /*
    |--------------------------------------------------------------------------
    | HTTP Timeout
    |--------------------------------------------------------------------------
    |
    | Seconds to wait on any single Kelviq call. Ten is the SDK's own default,
    | and every call here happens inside a request somebody is waiting on.
    |
    */

    'timeout' => 10,

    /*
    |--------------------------------------------------------------------------
    | Entitlement Cache
    |--------------------------------------------------------------------------
    |
    | How long, in seconds, an entitlement answer is trusted before Kelviq is
    | asked again, and how long the last good answer is kept to fall back on
    | while Kelviq cannot be reached. Sixty seconds is the SDK's own default.
    | See App\Queries\KelviqEntitlements.
    |
    */

    'cache_ttl' => 60,

    'stale_ttl' => 86_400,

];
