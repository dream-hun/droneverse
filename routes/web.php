<?php

declare(strict_types=1);

use App\Http\Controllers\AnalyticsController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\LeaderboardController;
use App\Http\Controllers\PrivacyController;
use App\Http\Controllers\TermsController;
use App\Http\Controllers\WelcomeController;
use App\Http\Controllers\WithdrawalFormController;
use Illuminate\Support\Facades\Route;

Route::get('/', WelcomeController::class)->name('home');

/*
 * Both public, and both linked from the register form rather than only from
 * the footer: EU law times these disclosures to before the contract and before
 * the first field is filled in, which is squarely before anyone has an account
 * to sign in with.
 */
Route::get('terms', TermsController::class)->name('terms');
Route::get('privacy', PrivacyController::class)->name('privacy');

/*
 * The Annex I(B) model withdrawal form. Article 6(1)(h) expects a trader to
 * make it available; the terms deliberately do not put its blanks on the page,
 * because withdrawing here is a person reading an email. A download satisfies
 * the first without reintroducing the second.
 *
 * Named as its own route rather than `terms.withdrawal-form`: `terms` is
 * already a route name, and Wayfinder would have to be both a function and a
 * namespace to generate the pair.
 */
Route::get('terms/withdrawal-form.pdf', WithdrawalFormController::class)
    ->name('withdrawal-form');

Route::middleware(['auth', 'verified'])->group(function (): void {
    Route::get('dashboard', DashboardController::class)->name('dashboard');
    Route::get('leaderboard', LeaderboardController::class)->name('leaderboard');

    // Advanced analytics is sold on Pro. The Gate is registered from the
    // Feature enum in AppServiceProvider, so naming the ability here is the
    // whole of the wiring.
    Route::get('analytics', AnalyticsController::class)
        ->middleware('can:advanced_analytics')
        ->name('analytics');
});

require __DIR__.'/settings.php';
require __DIR__.'/courses.php';
require __DIR__.'/billing.php';
