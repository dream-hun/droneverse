<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Creem API Key
    |--------------------------------------------------------------------------
    |
    | Sent as the `x-api-key` header on every request. Found under Developers
    | in the Creem dashboard, and different in test mode and live mode — the
    | two are not interchangeable, and the prefix on the key decides which host
    | it is sent to — see App\Http\Integrations\Creem.
    |
    | Server-side only. Nothing about a checkout reaches the browser except the
    | URL Creem mints for it; see App\Actions\BuildCreemClientConfig.
    |
    */

    'api_key' => env('CREEM_API_KEY'),

    /*
    |--------------------------------------------------------------------------
    | Webhook Secret
    |--------------------------------------------------------------------------
    |
    | Creem signs every webhook body with HMAC-SHA256 under this secret and
    | sends the digest in the `creem-signature` header. Found under
    | Developers > Webhook in the dashboard, and separate from the API key.
    |
    | App\Http\Middleware\VerifyCreemWebhookSignature refuses everything when
    | this is unset, deliberately: the webhook is the one endpoint that grants
    | paid access without a session behind it.
    |
    */

    'webhook_secret' => env('CREEM_WEBHOOK_SECRET'),

    /*
    |--------------------------------------------------------------------------
    | API Base URL
    |--------------------------------------------------------------------------
    |
    | An override, and normally unset. Creem has no sandbox flag: test mode is
    | a different host with its own products, customers and subscriptions, and
    | the key itself already says which of the two it belongs to — `creem_test_`
    | prefixes a test key and a live key has no prefix. App\Http\Integrations\Creem
    | derives the host from that rather than reading it from here, which is one
    | fewer value to get out of step with the other.
    |
    | Set this only to point somewhere else entirely: a proxy, or a recorded
    | fixture server.
    |
    */

    'api_url' => env('CREEM_API_URL'),

    /*
    |--------------------------------------------------------------------------
    | Webhook Url Path
    |--------------------------------------------------------------------------
    |
    | The base URI the webhook route is served from. Creem is told where to
    | post in its own dashboard rather than discovering it, so this only has to
    | agree with what was typed there — but it is configuration rather than a
    | literal so that an application mounted under a prefix can move it without
    | editing routes/billing.php.
    |
    */

    'path' => env('CREEM_PATH', 'creem'),

    /*
    |--------------------------------------------------------------------------
    | HTTP Timeout
    |--------------------------------------------------------------------------
    |
    | Seconds to wait on any single Creem call before giving up. Every call
    | this application makes happens inside a request a person is waiting on —
    | minting a checkout, changing a plan, opening the customer portal — so the
    | ceiling is set low enough that a slow provider becomes an error message
    | rather than a browser that appears to have frozen.
    |
    */

    'timeout' => (int) env('CREEM_TIMEOUT', 15),

    /*
    |--------------------------------------------------------------------------
    | Currency Locale
    |--------------------------------------------------------------------------
    |
    | The locale money is formatted in for display. Requires the "intl"
    | extension for anything other than the default. See App\Actions\FormatMoney.
    |
    */

    'currency_locale' => env('CREEM_CURRENCY_LOCALE', 'en'),

];
