<?php

declare(strict_types=1);

use App\Http\Controllers\CheckoutController;
use App\Http\Controllers\CreemWebhookController;
use App\Http\Controllers\PricingController;
use App\Http\Controllers\Settings\BillingController;
use App\Http\Controllers\Settings\SubscriptionController;
use App\Http\Controllers\SubscriptionThankYouController;
use App\Http\Middleware\VerifyCreemWebhookSignature;
use Illuminate\Support\Facades\Route;

/*
 * Public: every lock badge in the catalog points here, and most of the pilots
 * who follow one are not signed in yet.
 */
Route::get('pricing', PricingController::class)->name('pricing');

/*
 * Where Creem is told to post.
 *
 * Creem does not discover this URL; it is typed into the dashboard, under
 * Developers > Webhook, once per environment. The prefix is configuration
 * rather than a literal only so that an application mounted somewhere unusual
 * can move it without editing this file — the fallback is what everything else
 * assumes, so blanking the variable rather than removing the line still serves
 * the path the dashboard was told about.
 */
$creemPath = config('creem.path');
$creemPath = is_string($creemPath) && $creemPath !== '' ? $creemPath : 'creem';

/*
 * Creem posts here as a server, not as a browser: no session, no cookie, no
 * CSRF token. This file is required from routes/web.php, so it inherits the
 * whole `web` group, and the group is dropped wholesale for this one route
 * rather than only its CSRF middleware. Nothing in the group applies — a
 * session that is started and immediately discarded on every webhook is churn,
 * and Inertia has no part in a machine-to-machine POST — and excluding the
 * group by name cannot drift the way naming one middleware class can. (It
 * already did once: the CSRF middleware is
 * Illuminate\Foundation\Http\Middleware\PreventRequestForgery as of Laravel 13,
 * ValidateCsrfToken being only a deprecated subclass of it, so excluding the
 * old name would have silently left CSRF enabled here.)
 *
 * The signature is what authenticates the caller, and
 * VerifyCreemWebhookSignature applies it unconditionally — an environment that
 * has not configured a webhook secret accepts nothing at all rather than
 * accepting everything.
 */
Route::prefix($creemPath)->group(function (): void {
    Route::post('webhook', CreemWebhookController::class)
        ->withoutMiddleware('web')
        ->middleware([VerifyCreemWebhookSignature::class])
        ->name('creem.webhook');
});

Route::middleware(['auth'])->group(function (): void {
    /*
     * Throttled because each call creates a checkout session at Creem and an
     * abandoned overlay costs an API round trip either way.
     */
    Route::post('checkout', [CheckoutController::class, 'store'])
        ->middleware('throttle:20,1')
        ->name('checkout.store');

    /*
     * The page a completed checkout lands on.
     *
     * A GET the buyer can bookmark, reload and come back to, deliberately: the
     * plan is granted by a webhook that arrives after the browser does, so the
     * page has to survive being asked the same question again a few seconds
     * later. Nothing about which checkout it is travels in the URL — see
     * App\Actions\BuildSubscriptionConfirmation.
     *
     * It is also the `success_url` on every checkout this application mints, so
     * a buyer whose browser blocked the embed script and paid on Creem's own
     * page arrives in the same place as one who never left.
     */
    Route::get('subscription/thank-you', SubscriptionThankYouController::class)
        ->name('subscription.thank-you');

    Route::get('settings/billing', [BillingController::class, 'edit'])->name('billing.edit');

    /*
     * Creem's customer portal covers the card on file, the invoices and a
     * cancellation of last resort, so the route is named for the portal rather
     * than for the button that reaches it.
     */
    Route::get('settings/billing/portal', [SubscriptionController::class, 'edit'])
        ->name('billing-portal.edit');

    Route::put('settings/subscription', [SubscriptionController::class, 'update'])->name('subscription.update');
    Route::delete('settings/subscription', [SubscriptionController::class, 'destroy'])->name('subscription.destroy');

    /*
     * Changing plan is a live POST to Creem that reprices a subscription and
     * settles the difference on the spot, so it is throttled the way checkout
     * is. Its own route rather than another shape of `subscription.update`,
     * which already means "call off a cancellation": one endpoint answering to
     * both would decide which by whether a body happened to be posted.
     */
    Route::put('settings/subscription/plan', [SubscriptionController::class, 'swap'])
        ->middleware('throttle:20,1')
        ->name('subscription.swap');
});
