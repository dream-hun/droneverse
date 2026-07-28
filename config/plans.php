<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Paddle Price IDs
    |--------------------------------------------------------------------------
    |
    | The single map from a plan and one of its variants to the Paddle price ID
    | that sells it. Price IDs are environment configuration, never database
    | rows: the sandbox and production catalogues carry different IDs for the
    | same product, and a row would let a client-supplied value reach checkout.
    |
    | App\Enums\Plan reads this map, and App\Actions\ResolvePlanForUser reads it
    | in reverse — mapping a subscribed price ID back onto the plan it grants.
    | Every variant listed here must therefore be unique across all plans.
    |
    | Starter is free and Enterprise is sales-led, so neither has a price ID.
    |
    | Everything here is a subscription. There is no one-off purchase: a plan
    | sold once and honoured forever prices a bet on costs nobody can see yet,
    | and it is the one sale that cannot be repriced afterwards.
    |
    */

    'prices' => [

        'pro' => [
            'monthly' => env('PADDLE_PRICE_PRO_MONTHLY'),
            'yearly' => env('PADDLE_PRICE_PRO_YEARLY'),

            /*
             * Launch pricing for the first 100 paying customers ($15/mo,
             * $150/yr). "First 100" counts customers who have paid, not
             * accounts that have registered: a signup-based count leaks the
             * discount to people who never convert and is bounded by nothing
             * you control. Phase 5 picks these in ResolveCheckoutPrice.
             */
            'monthly_launch' => env('PADDLE_PRICE_PRO_MONTHLY_LAUNCH'),
            'yearly_launch' => env('PADDLE_PRICE_PRO_YEARLY_LAUNCH'),
        ],

        'team' => [
            'monthly' => env('PADDLE_PRICE_TEAM_MONTHLY'),
            'yearly' => env('PADDLE_PRICE_TEAM_YEARLY'),

            /*
             * The quantity-based seat prices Phase 7 increments on invite.
             * $5/mo per student past the ten the base subscription covers.
             */
            'seat_monthly' => env('PADDLE_PRICE_TEAM_SEAT_MONTHLY'),
            'seat_yearly' => env('PADDLE_PRICE_TEAM_SEAT_YEARLY'),
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Display Amounts
    |--------------------------------------------------------------------------
    |
    | What the pricing page quotes, in minor units of cashier.currency. Paddle
    | remains authoritative for what is actually charged — checkout only ever
    | sends a price ID, never an amount — so these numbers are copy, and they
    | are kept beside the IDs they describe precisely so the two are edited in
    | the same breath. A variant priced here but unlisted above simply cannot
    | be bought; a variant with an ID but no amount cannot be advertised.
    |
    | Paddle's price preview API would remove the duplication, but it needs
    | live credentials to answer, which would make the pricing page unrenderable
    | in tests and in any environment without a Paddle account.
    |
    */

    /*
    |--------------------------------------------------------------------------
    | Sales Contact
    |--------------------------------------------------------------------------
    |
    | Where the Team and Enterprise buttons point until Phases 7-8 make those
    | tiers self-serve, and where docs/pricing.md sends institutions asking
    | about academic pricing. Unset, those buttons render disabled rather than
    | opening an empty mail client.
    |
    */

    'sales_email' => env('SALES_EMAIL'),

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
