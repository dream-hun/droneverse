<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Google Tag Manager
    |--------------------------------------------------------------------------
    |
    | The container loaded for visitors who accept analytics, and only for
    | them, and only in production. See the `tagManager` prop shared in
    | App\Http\Middleware\HandleInertiaRequests and the consent banner in
    | resources/js/components/cookie-consent.tsx.
    |
    | A container ID is not a secret: it is printed into every page that loads
    | it. So the real one is the default, and a deployment needs nothing set
    | to use it. Setting GOOGLE_TAG_MANAGER_ID to an empty value turns it off.
    |
    */

    'google_tag_manager' => [
        'container_id' => env('GOOGLE_TAG_MANAGER_ID', 'GTM-TTP6H9WW'),
    ],

];
