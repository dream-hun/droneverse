<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Creem Product IDs
    |--------------------------------------------------------------------------
    |
    | The single map from a plan and one of its variants to the Creem product
    | that sells it. Those IDs are environment configuration, never database
    | rows: a test store and a live store are isolated environments carrying
    | different IDs for the same thing, and a row would let a client-supplied
    | value reach checkout.
    |
    | Note the word "variant" is doing one job here and Creem does not use it at
    | all. This application's variants are billing periods — monthly, yearly,
    | the launch prices below. Creem has no separate price object: a product
    | carries its own amount and its own billing period, so one product is one
    | purchasable price and "Pro monthly" and "Pro yearly" are two products.
    | The keys of this array are billing periods; the values are product IDs.
    | App\Enums\Plan calls the values "price IDs" throughout, which is the
    | provider-neutral name for whatever identifies the thing being sold.
    |
    | App\Enums\Plan reads this map, and App\Actions\ResolvePlanForUser reads it
    | in reverse — mapping a subscribed product ID back onto the plan it grants.
    | Every product listed here must therefore be unique across all plans.
    |
    | Starter is free, so it has no ID.
    |
    | Everything here is a subscription. There is no one-off purchase: a plan
    | sold once and honoured forever prices a bet on costs nobody can see yet,
    | and it is the one sale that cannot be repriced afterwards.
    |
    */

    'prices' => [

        'pro' => [
            'monthly' => env('CREEM_PRODUCT_PRO_MONTHLY'),
            'yearly' => env('CREEM_PRODUCT_PRO_YEARLY'),

            /*
             * Launch pricing for the first 100 paying customers ($15/mo,
             * $150/yr). "First 100" counts customers who have paid, not
             * accounts that have registered: a signup-based count leaks the
             * discount to people who never convert and is bounded by nothing
             * you control. Phase 5 picks these in ResolveCheckoutPrice.
             */
            'monthly_launch' => env('CREEM_PRODUCT_PRO_MONTHLY_LAUNCH'),
            'yearly_launch' => env('CREEM_PRODUCT_PRO_YEARLY_LAUNCH'),
        ],

        'team' => [
            'monthly' => env('CREEM_PRODUCT_TEAM_MONTHLY'),
            'yearly' => env('CREEM_PRODUCT_TEAM_YEARLY'),

            /*
             * The per-seat products Phase 7 bills against on invite. $5/month
             * per student past the ten the base subscription covers. Creem
             * charges `base_price × units` on a checkout, so a seat count is a
             * `units` value against one of these rather than a product each.
             */
            'seat_monthly' => env('CREEM_PRODUCT_TEAM_SEAT_MONTHLY'),
            'seat_yearly' => env('CREEM_PRODUCT_TEAM_SEAT_YEARLY'),
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Display Amounts
    |--------------------------------------------------------------------------
    |
    | What the pricing page quotes, in minor units of plans.currency below.
    | Creem remains authoritative for what is actually charged — checkout only
    | ever sends a product ID, never an amount — so these numbers are copy, and
    | they are kept beside the IDs they describe precisely so the two are edited
    | in the same breath. A variant priced here but unlisted above simply cannot
    | be bought; a variant with an ID but no amount cannot be advertised.
    |
    | Reading the prices back from the Creem API would remove the duplication,
    | but it needs live credentials to answer, which would make the pricing page
    | unrenderable in tests and in any environment without a Creem account.
    |
    */

    /*
    |--------------------------------------------------------------------------
    | Sales Contact
    |--------------------------------------------------------------------------
    |
    | Where docs/pricing.md sends institutions asking about academic pricing,
    | and the address /terms and /privacy fall back to when neither contact in
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
    | page formats them with. It lives here rather than in config/creem.php
    | because it describes this application's copy, not the provider: Creem
    | decides what a customer is actually charged, and as merchant of record it
    | will happily quote a different currency at checkout than the one
    | advertised here. Keeping it beside the amounts means a repricing into
    | another currency is one edit in one file.
    |
    */

    'currency' => env('CREEM_CURRENCY', 'USD'),

    'amounts' => [

        'pro' => [
            'monthly' => 1900,
            'yearly' => 19000,
            'monthly_launch' => 1500,
            'yearly_launch' => 15000,
        ],

        'team' => [
            'monthly' => 5900,
            'yearly' => 59000,
            'seat_monthly' => 500,
            'seat_yearly' => 5000,
        ],

    ],

];
