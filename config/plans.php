<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Lemon Squeezy Variant IDs
    |--------------------------------------------------------------------------
    |
    | The single map from a plan and one of its variants to the Lemon Squeezy
    | variant ID that sells it. Those IDs are environment configuration, never
    | database rows: a test store and a live store carry different IDs for the
    | same product, and a row would let a client-supplied value reach checkout.
    |
    | Note the word "variant" is doing two jobs here, and they are not the same
    | job. This application's variants are billing periods — monthly, yearly,
    | the launch prices below. A Lemon Squeezy variant is the purchasable price
    | object, which is what the values are. The keys of this array are the
    | former; the values identify the latter. App\Enums\Plan calls the values
    | "price IDs" throughout for exactly that reason: it is the provider-neutral
    | name for whatever identifies the thing being sold.
    |
    | App\Enums\Plan reads this map, and App\Actions\ResolvePlanForUser reads it
    | in reverse — mapping a subscribed variant ID back onto the plan it grants.
    | Every variant listed here must therefore be unique across all plans.
    |
    | Starter is free and Enterprise is sales-led, so neither has an ID.
    |
    | Everything here is a subscription. There is no one-off purchase: a plan
    | sold once and honoured forever prices a bet on costs nobody can see yet,
    | and it is the one sale that cannot be repriced afterwards.
    |
    */

    'prices' => [

        'pro' => [
            'monthly' => env('LEMON_SQUEEZY_VARIANT_PRO_MONTHLY'),
            'yearly' => env('LEMON_SQUEEZY_VARIANT_PRO_YEARLY'),

            /*
             * Launch pricing for the first 100 paying customers ($15/mo,
             * $150/yr). "First 100" counts customers who have paid, not
             * accounts that have registered: a signup-based count leaks the
             * discount to people who never convert and is bounded by nothing
             * you control. Phase 5 picks these in ResolveCheckoutPrice.
             */
            'monthly_launch' => env('LEMON_SQUEEZY_VARIANT_PRO_MONTHLY_LAUNCH'),
            'yearly_launch' => env('LEMON_SQUEEZY_VARIANT_PRO_YEARLY_LAUNCH'),
        ],

        'team' => [
            'monthly' => env('LEMON_SQUEEZY_VARIANT_TEAM_MONTHLY'),
            'yearly' => env('LEMON_SQUEEZY_VARIANT_TEAM_YEARLY'),

            /*
             * The quantity-based seat prices Phase 7 increments on invite.
             * $5/mo per student past the ten the base subscription covers.
             */
            'seat_monthly' => env('LEMON_SQUEEZY_VARIANT_TEAM_SEAT_MONTHLY'),
            'seat_yearly' => env('LEMON_SQUEEZY_VARIANT_TEAM_SEAT_YEARLY'),
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Lemon Squeezy Product IDs
    |--------------------------------------------------------------------------
    |
    | The product each plan's variants belong to. Checkout never needs these —
    | a variant ID is enough to sell something — but changing the plan of a
    | subscription that already exists does: Lemon Squeezy's update endpoint
    | takes a product and a variant together, and refuses a variant that does
    | not belong to the product named beside it.
    |
    | Only the tiers a pilot can move between need one, which is the same set as
    | `prices` above minus the sales-led tiers. App\Actions\SwapSubscription
    | falls back to the product the subscription is already on when a pilot only
    | changes billing period, so an environment that has never set these can
    | still switch monthly to yearly — it just cannot move between tiers.
    |
    */

    'products' => [
        'pro' => env('LEMON_SQUEEZY_PRODUCT_PRO'),
        'team' => env('LEMON_SQUEEZY_PRODUCT_TEAM'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Display Amounts
    |--------------------------------------------------------------------------
    |
    | What the pricing page quotes, in minor units of plans.currency below.
    | Lemon Squeezy remains authoritative for what is actually charged —
    | checkout only ever sends a variant ID, never an amount — so these numbers
    | are copy, and they are kept beside the IDs they describe precisely so the
    | two are edited in the same breath. A variant priced here but unlisted
    | above simply cannot be bought; a variant with an ID but no amount cannot
    | be advertised.
    |
    | Reading the prices back from the Lemon Squeezy API would remove the
    | duplication, but it needs live credentials to answer, which would make the
    | pricing page unrenderable in tests and in any environment without a Lemon
    | Squeezy store.
    |
    */

    /*
    |--------------------------------------------------------------------------
    | Sales Contact
    |--------------------------------------------------------------------------
    |
    | Where the Enterprise button points — a private deployment and an SLA are
    | negotiated, not bought from a card form — and where docs/pricing.md sends
    | institutions asking about academic pricing. Unset, that button renders
    | disabled rather than opening an empty mail client.
    |
    */

    'sales_email' => env('SALES_EMAIL'),

    /*
    |--------------------------------------------------------------------------
    | Display Currency
    |--------------------------------------------------------------------------
    |
    | The currency the amounts below are denominated in, and the one the pricing
    | page formats them with. It lives here rather than in config/lemon-squeezy.php
    | because it describes this application's copy, not the provider: Lemon
    | Squeezy decides what a customer is actually charged, and it will happily
    | quote a different currency at checkout than the one advertised here.
    | Keeping it beside the amounts means a repricing into another currency is
    | one edit in one file.
    |
    */

    'currency' => env('LEMON_SQUEEZY_CURRENCY', 'USD'),

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
