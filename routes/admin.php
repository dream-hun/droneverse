<?php

declare(strict_types=1);

use App\Http\Controllers\Admin\ActivityController;
use App\Http\Controllers\Admin\ChallengeController;
use App\Http\Controllers\Admin\CourseController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\FailedJobController;
use App\Http\Controllers\Admin\FinanceController;
use App\Http\Controllers\Admin\OrderController;
use App\Http\Controllers\Admin\RoleController;
use App\Http\Controllers\Admin\SubscriptionController;
use App\Http\Controllers\Admin\SystemController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\Admin\UserVerificationController;
use Illuminate\Support\Facades\Route;

/*
 * The admin area.
 *
 * Every route here needs `access_admin`, and each section needs its own
 * permission on top — see App\Enums\AdminPermission for why the door and the
 * rooms are separate. The `can:` middleware reaches spatie/laravel-permission
 * through the Gate, the same way `can:advanced_analytics` reaches a plan, so
 * one mechanism guards both kinds of capability.
 *
 * `verified` as well as `auth`: an address nobody has confirmed is not one the
 * business has any reason to trust with its customers' data.
 */
Route::middleware(['auth', 'verified', 'can:access_admin'])
    ->prefix('admin')
    ->name('admin.')
    ->group(function (): void {
        Route::get('/', DashboardController::class)->name('dashboard');
        Route::get('activity', ActivityController::class)->name('activity');

        Route::middleware('can:manage_users')->group(function (): void {
            Route::get('users', [UserController::class, 'index'])->name('users.index');
            Route::post('users', [UserController::class, 'store'])->name('users.store');
            Route::get('users/{user}', [UserController::class, 'show'])->name('users.show');
            Route::put('users/{user}', [UserController::class, 'update'])->name('users.update');
            Route::delete('users/{user}', [UserController::class, 'destroy'])->name('users.destroy');

            Route::post('users/{user}/verification', [UserVerificationController::class, 'store'])
                ->name('users.verification.store');
        });

        Route::middleware('can:manage_roles')->group(function (): void {
            Route::get('roles', [RoleController::class, 'index'])->name('roles.index');
            Route::post('roles', [RoleController::class, 'store'])->name('roles.store');
            Route::put('roles/{role}', [RoleController::class, 'update'])->name('roles.update');
            Route::delete('roles/{role}', [RoleController::class, 'destroy'])->name('roles.destroy');
        });

        /*
         * Courses and their missions are addressed by slug here as they are
         * on the public side, so an admin URL and the page it edits name the
         * same thing. A mission's slug is unique only within its course,
         * which is what the scoped bindings are for: a real mission under the
         * wrong course is a 404, not somebody else's mission.
         */
        Route::middleware('can:manage_courses')->group(function (): void {
            Route::get('courses', [CourseController::class, 'index'])->name('courses.index');
            Route::post('courses', [CourseController::class, 'store'])->name('courses.store');
            Route::get('courses/{course:slug}', [CourseController::class, 'show'])->name('courses.show');
            Route::put('courses/{course:slug}', [CourseController::class, 'update'])->name('courses.update');
            Route::delete('courses/{course:slug}', [CourseController::class, 'destroy'])->name('courses.destroy');

            Route::scopeBindings()->group(function (): void {
                Route::post('courses/{course:slug}/challenges', [ChallengeController::class, 'store'])
                    ->name('courses.challenges.store');
                Route::put('courses/{course:slug}/challenges/{challenge:slug}', [ChallengeController::class, 'update'])
                    ->name('courses.challenges.update');
                Route::delete('courses/{course:slug}/challenges/{challenge:slug}', [ChallengeController::class, 'destroy'])
                    ->name('courses.challenges.destroy');
            });
        });

        Route::middleware('can:view_finance')->group(function (): void {
            Route::get('finance', FinanceController::class)->name('finance');
            Route::get('finance/orders', OrderController::class)->name('finance.orders');
            Route::get('finance/subscriptions', SubscriptionController::class)->name('finance.subscriptions');
        });

        /*
         * A failed job is named by the uuid the queue's failer gave it rather
         * than by a model, because there is no model: the table belongs to the
         * framework, and the failer is the only thing that should write it.
         */
        Route::middleware('can:view_system')->group(function (): void {
            Route::get('system', SystemController::class)->name('system');

            Route::post('system/failed-jobs/{failedJob}/retry', [FailedJobController::class, 'retry'])
                ->whereUuid('failedJob')
                ->name('system.failed-jobs.retry');
            Route::delete('system/failed-jobs/{failedJob}', [FailedJobController::class, 'destroy'])
                ->whereUuid('failedJob')
                ->name('system.failed-jobs.destroy');
        });
    });
