<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Operating Entity
    |--------------------------------------------------------------------------
    |
    | Who a visitor is actually contracting with, and where to serve them. EU
    | law asks for this twice over and for different reasons: the e-commerce
    | rules want a trader identifiable before a sale, and GDPR Article 13 wants
    | the controller identifiable before a form is filled in. Both are answered
    | from here.
    |
    | Per environment rather than committed, because staging and production are
    | not the same trader — a review app that names the live company is
    | inviting someone to send a withdrawal notice into a deployment nobody
    | reads. The name falls back to the application's own in BuildLegalIdentity;
    | the rest render only when they are set, so an unconfigured deployment
    | publishes a page with a gap in it rather than a page with an invented
    | address on it.
    |
    */

    'entity' => [
        'name' => env('LEGAL_ENTITY_NAME'),
        'address' => env('LEGAL_ENTITY_ADDRESS'),
        'country' => env('LEGAL_ENTITY_COUNTRY'),
        'registration' => env('LEGAL_ENTITY_REGISTRATION'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Contact Addresses
    |--------------------------------------------------------------------------
    |
    | Two inboxes because the deadlines behind them differ: a data subject
    | request runs on GDPR's one month, a withdrawal notice on the Consumer
    | Rights Directive's fourteen days, and a company large enough to route
    | them separately should be able to say so. A company that is not can set
    | one address and let the other fall back to it.
    |
    | Both fall back through SALES_EMAIL last, so a deployment that has already
    | configured somewhere to reach a human is never published with no way to
    | exercise a right. See App\Actions\BuildLegalIdentity for the order.
    |
    */

    'contact' => [
        'support' => env('LEGAL_SUPPORT_EMAIL'),
        'privacy' => env('LEGAL_PRIVACY_EMAIL'),
    ],

    /*
    |--------------------------------------------------------------------------
    | EU Representative
    |--------------------------------------------------------------------------
    |
    | GDPR Article 27: a controller established outside the EU that offers
    | services to people in the EU must designate a representative there, in
    | writing, and name them where data subjects can find them. This is that
    | naming. It is also the one section of the privacy policy that is a claim
    | about a mandate rather than about our own conduct, which is why it is
    | rendered only when configured — publishing the heading with nothing under
    | it announces a breach, and publishing a name we have not actually
    | appointed is worse.
    |
    | Article 27(2) exempts occasional, low-risk processing. Selling
    | subscriptions to EU pilots month after month is neither.
    |
    */

    'eu_representative' => [
        'name' => env('LEGAL_EU_REPRESENTATIVE_NAME'),
        'address' => env('LEGAL_EU_REPRESENTATIVE_ADDRESS'),
        'email' => env('LEGAL_EU_REPRESENTATIVE_EMAIL'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Where The Data Lives
    |--------------------------------------------------------------------------
    |
    | Named in plain words — "the United States", "Rwanda and the European
    | Union" — because Article 13(1)(f) makes the fact of a transfer out of the
    | EEA a disclosure in its own right, separate from the safeguards that
    | make it lawful. This follows the hosting region and the object store, so
    | it is set beside them and changed when they move.
    |
    */

    'hosting_region' => env('LEGAL_HOSTING_REGION'),

    /*
    |--------------------------------------------------------------------------
    | Effective Dates
    |--------------------------------------------------------------------------
    |
    | Literals, not environment variables. The date states when this wording
    | took effect, so it is a fact about the copy in resources/js/pages/legal/
    | and belongs in the same commit as an edit to it — an environment variable
    | would let a deployment claim a revision date for text it is not serving.
    |
    | Amending either document means bumping its date here, and material
    | changes to the terms also mean the thirty days' notice those terms
    | promise, which is a mail-out and not a config change.
    |
    */

    'effective' => [
        'terms' => '2026-08-21',
        'privacy' => '2026-08-20',
    ],

];
