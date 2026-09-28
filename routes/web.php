<?php

declare(strict_types=1);

use App\Http\Controllers\AnalyticsController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DocsController;
use App\Http\Controllers\LeaderboardController;
use App\Http\Controllers\PrivacyController;
use App\Http\Controllers\RobotsController;
use App\Http\Controllers\SitemapController;
use App\Http\Controllers\TermsController;
use App\Http\Controllers\WelcomeController;
use App\Http\Controllers\WithdrawalFormController;
use Illuminate\Support\Facades\Route;

Route::get('/', WelcomeController::class)->name('home');
Route::get('docs', DocsController::class)->name('docs');
Route::get('terms', TermsController::class)->name('terms');
Route::get('privacy', PrivacyController::class)->name('privacy');
Route::get('terms/withdrawal-form.pdf', WithdrawalFormController::class)
    ->name('withdrawal-form');

/*
 * Read by crawlers, never by a person in a browser, so the `web` group is
 * dropped: no session row written for every bot that asks, no cookies, no
 * CSRF token. Cached for an hour with an ETag, since crawlers ask for both
 * often and neither changes from one request to the next.
 */
Route::get('robots.txt', RobotsController::class)
    ->withoutMiddleware('web')
    ->middleware('cache.headers:public;max_age=3600;etag')
    ->name('robots');
Route::get('sitemap.xml', SitemapController::class)
    ->withoutMiddleware('web')
    ->middleware('cache.headers:public;max_age=3600;etag')
    ->name('sitemap');

Route::middleware(['auth', 'verified'])->group(function (): void {
    Route::get('dashboard', DashboardController::class)->name('dashboard');
    Route::get('leaderboard', LeaderboardController::class)->name('leaderboard');
    Route::get('analytics', AnalyticsController::class)
        ->middleware('can:advanced_analytics')
        ->name('analytics');
});

require __DIR__.'/settings.php';
require __DIR__.'/courses.php';
require __DIR__.'/billing.php';
require __DIR__.'/admin.php';
