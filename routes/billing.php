<?php

declare(strict_types=1);

use App\Http\Controllers\CheckoutController;
use App\Http\Controllers\PricingController;
use App\Http\Controllers\Settings\BillingController;
use App\Http\Controllers\Settings\SubscriptionController;
use Illuminate\Support\Facades\Route;

/*
 * Public: every lock badge in the catalog points here, and most of the pilots
 * who follow one are not signed in yet.
 */
Route::get('pricing', PricingController::class)->name('pricing');

Route::middleware(['auth'])->group(function (): void {
    /*
     * Throttled because each call creates a Paddle customer on first use and
     * an abandoned overlay costs an API round trip either way.
     */
    Route::post('checkout', [CheckoutController::class, 'store'])
        ->middleware('throttle:20,1')
        ->name('checkout.store');

    Route::get('settings/billing', [BillingController::class, 'edit'])->name('billing.edit');

    Route::get('settings/billing/payment-method', [SubscriptionController::class, 'edit'])
        ->name('payment-method.edit');

    Route::put('settings/subscription', [SubscriptionController::class, 'update'])->name('subscription.update');
    Route::delete('settings/subscription', [SubscriptionController::class, 'destroy'])->name('subscription.destroy');
});
