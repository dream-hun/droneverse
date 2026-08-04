<?php

declare(strict_types=1);

use App\Http\Controllers\AnalyticsController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\LeaderboardController;
use App\Http\Controllers\WelcomeController;
use Illuminate\Support\Facades\Route;

Route::get('/', WelcomeController::class)->name('home');

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
