<?php

declare(strict_types=1);

use App\Http\Controllers\CheckoutController;
use App\Http\Controllers\KelviqWebhookController;
use App\Http\Controllers\PricingController;
use App\Http\Controllers\Settings\BillingController;
use App\Http\Controllers\Settings\BillingPortalController;
use App\Http\Controllers\SubscriptionThankYouController;
use App\Http\Middleware\VerifyKelviqWebhookSignature;
use Illuminate\Support\Facades\Route;

/*
 * Public: every lock badge in the catalog points here, and most of the pilots
 * who follow one are not signed in yet.
 */
Route::get('pricing', PricingController::class)->name('pricing');

/*
 * Where Kelviq is told to post, by `npx kelviq webhook add <url>/api/kelviq/webhooks`.
 *
 * Kelviq posts here as a server, not as a browser: no session, no cookie, no
 * CSRF token. This file is required from routes/web.php, so it inherits the
 * whole `web` group, and the group is dropped wholesale for this one route
 * rather than only its CSRF middleware — nothing in it applies to a
 * machine-to-machine POST, and excluding the group by name cannot drift the way
 * naming one middleware class can.
 *
 * The signature is what authenticates the caller, and
 * VerifyKelviqWebhookSignature applies it unconditionally.
 */
Route::post('api/kelviq/webhooks', KelviqWebhookController::class)
    ->withoutMiddleware('web')
    ->middleware([VerifyKelviqWebhookSignature::class])
    ->name('kelviq.webhook');

Route::middleware(['auth'])->group(function (): void {
    /*
     * Throttled because each call creates a checkout session at Kelviq, and an
     * abandoned one costs an API round trip either way.
     */
    Route::post('checkout', [CheckoutController::class, 'store'])
        ->middleware('throttle:20,1')
        ->name('checkout.store');

    /*
     * The page a completed checkout lands on, and the `success_url` on every
     * checkout this application mints.
     *
     * A GET the buyer can bookmark, reload and come back to, deliberately: the
     * plan is granted a moment after the browser arrives, so the page has to
     * survive being asked the same question again a few seconds later.
     */
    Route::get('subscription/thank-you', SubscriptionThankYouController::class)
        ->name('subscription.thank-you');

    Route::get('settings/billing', [BillingController::class, 'edit'])->name('billing.edit');

    /*
     * Kelviq's customer portal covers the card on file, invoices, the billing
     * period and cancelling, so the route is named for the portal rather than
     * for any one of those.
     */
    Route::get('settings/billing/portal', [BillingPortalController::class, 'edit'])
        ->middleware('throttle:20,1')
        ->name('billing-portal.edit');
});
