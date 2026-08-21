<?php

declare(strict_types=1);

use App\Http\Controllers\CheckoutController;
use App\Http\Controllers\PricingController;
use App\Http\Controllers\Settings\BillingController;
use App\Http\Controllers\Settings\SubscriptionController;
use App\Http\Controllers\SubscriptionThankYouController;
use App\Http\Middleware\PreventLemonSqueezyWebhookRetryLoops;
use App\Http\Middleware\VerifyLemonSqueezyWebhookSignature;
use Illuminate\Support\Facades\Route;
use LemonSqueezy\Laravel\Http\Controllers\WebhookController;

/*
 * Public: every lock badge in the catalog points here, and most of the pilots
 * who follow one are not signed in yet.
 */
Route::get('pricing', PricingController::class)->name('pricing');

/*
 * Where Lemon Squeezy is told to post, resolved rather than written out.
 *
 * This file is not the only thing that decides where the webhook lives.
 * `php artisan lmsqueezy:listen expose` — the command that fronts a local
 * machine with a tunnel and registers the resulting URL with Lemon Squeezy —
 * advertises it as `{tunnel}/{config('lemon-squeezy.path')}/webhook`, and the
 * package's own registration (dropped here; see AppServiceProvider) prefixed
 * its route with the same value. A literal path served one URL while both of
 * those published another, so setting LEMON_SQUEEZY_PATH pointed every
 * delivery at a 404 — and a 404 is not 2xx, so Lemon Squeezy redelivers it for
 * days rather than failing loudly once.
 *
 * The fallback matches the package's own config default, so an environment
 * that blanks the variable rather than removing the line still resolves to the
 * path everything else assumes.
 */
$lemonSqueezyPath = config('lemon-squeezy.path');
$lemonSqueezyPath = is_string($lemonSqueezyPath) && $lemonSqueezyPath !== ''
    ? $lemonSqueezyPath
    : 'lemon-squeezy';

/*
 * Lemon Squeezy posts here as a server, not as a browser: no session, no cookie,
 * no CSRF token. This file is required from routes/web.php, so it inherits the
 * whole `web` group, and the group is dropped wholesale for this one route
 * rather than only its CSRF middleware. Nothing in the group applies — a session
 * that is started and immediately discarded on every webhook is churn, and
 * Inertia has no part in a machine-to-machine POST — and excluding the group by
 * name cannot drift the way naming one middleware class can. (It already did:
 * the CSRF middleware is Illuminate\Foundation\Http\Middleware\PreventRequestForgery
 * as of Laravel 13, ValidateCsrfToken being only a deprecated subclass of it, so
 * excluding the old name would have silently left CSRF enabled here.)
 *
 * The signature is what authenticates the caller, and
 * VerifyLemonSqueezyWebhookSignature applies it unconditionally — unlike the
 * package's own route registration, which checks a signature only when a secret
 * happens to be configured. AppServiceProvider calls LemonSqueezy::ignoreRoutes()
 * so this is the only registration of this endpoint.
 *
 * PreventLemonSqueezyWebhookRetryLoops sits behind the signature check, so it
 * reads only bodies that have already been authenticated. It answers the
 * deliveries the controller would otherwise refuse forever — one already
 * recorded, one naming an account we do not have — because Lemon Squeezy
 * redelivers everything that is not 2xx and neither of those improves with
 * repetition.
 */
Route::prefix($lemonSqueezyPath)->group(function (): void {
    Route::post('webhook', WebhookController::class)
        ->withoutMiddleware('web')
        ->middleware([
            VerifyLemonSqueezyWebhookSignature::class,
            PreventLemonSqueezyWebhookRetryLoops::class,
        ])
        ->name('lemon-squeezy.webhook');
});

Route::middleware(['auth'])->group(function (): void {
    /*
     * Throttled because each call creates a Lemon Squeezy customer on first use
     * and an abandoned overlay costs an API round trip either way.
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
     */
    Route::get('subscription/thank-you', SubscriptionThankYouController::class)
        ->name('subscription.thank-you');

    Route::get('settings/billing', [BillingController::class, 'edit'])->name('billing.edit');

    Route::get('settings/billing/payment-method', [SubscriptionController::class, 'edit'])
        ->name('payment-method.edit');

    Route::put('settings/subscription', [SubscriptionController::class, 'update'])->name('subscription.update');
    Route::delete('settings/subscription', [SubscriptionController::class, 'destroy'])->name('subscription.destroy');

    /*
     * Changing plan is a live PATCH to Lemon Squeezy that reprices a
     * subscription, so it is throttled the way checkout is. Its own route
     * rather than another shape of `subscription.update`, which already means
     * "call off a cancellation": one endpoint answering to both would decide
     * which by whether a body happened to be posted.
     */
    Route::put('settings/subscription/plan', [SubscriptionController::class, 'swap'])
        ->middleware('throttle:20,1')
        ->name('subscription.swap');
});
