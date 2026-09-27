<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Sales Contact
    |--------------------------------------------------------------------------
    |
    | Where institutions asking about academic pricing are sent, and the
    | address /terms and /privacy fall back to when neither contact in
    | config/legal.php is set — see App\Actions\BuildLegalIdentity. Nothing on
    | the pricing page reads it: every tier there is bought with a card.
    |
    */

    'sales_email' => env('SALES_EMAIL'),

    /*
    |--------------------------------------------------------------------------
    | Display Currency
    |--------------------------------------------------------------------------
    |
    | The currency the amounts below are denominated in, and the one the pricing
    | page formats them with. It matches the `currency` of the prices in
    | kelviq.config.ts; Kelviq, as merchant of record, decides what a customer
    | is actually charged, and may quote a local currency at checkout.
    |
    */

    'currency' => 'USD',

    /*
     * The locale money is formatted in for display. Requires the "intl"
     * extension for anything other than the default; see App\Actions\FormatMoney.
     */
    'currency_locale' => 'en',

    /*
    |--------------------------------------------------------------------------
    | Display Amounts
    |--------------------------------------------------------------------------
    |
    | What the pricing page quotes, in minor units of the currency above.
    | Kelviq remains authoritative for what is actually charged — checkout only
    | ever names a plan and a billing period, never an amount — so these numbers
    | are copy, and they must be edited in the same breath as the prices in
    | kelviq.config.ts.
    |
    | Reading the prices back from Kelviq would remove the duplication, but it
    | needs credentials to answer, which would make the pricing page
    | unrenderable in tests and in any environment without a Kelviq account.
    |
    | `lifetime` is Pro paid for once, sold as its own Kelviq plan
    | (`pro-lifetime`). It is the one sale here that cannot be repriced for the
    | people who already hold it, which is worth remembering before changing
    | what Pro includes.
    |
    */

    'amounts' => [

        'pro' => [
            'monthly' => 1900,
            'yearly' => 19000,
            'lifetime' => 35000,
        ],

    ],

];
