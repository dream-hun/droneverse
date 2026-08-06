<?php

declare(strict_types=1);

use App\Http\Controllers\ChallengeController;
use App\Http\Controllers\ChallengeDroneController;
use App\Http\Controllers\CourseController;
use App\Http\Controllers\DronePhotoController;
use Illuminate\Support\Facades\Route;

Route::get('courses', [CourseController::class, 'index'])->name('courses.index');
Route::get('courses/{course:slug}', [CourseController::class, 'show'])->name('courses.show');

Route::middleware(['auth', 'verified'])->group(function (): void {
    Route::get('courses/{course:slug}/challenges/{challenge:slug}', [ChallengeController::class, 'show'])->name('challenges.show');

    // A mission takes at least ten seconds to fly and a run submits once, so
    // these ceilings sit far above any pilot flying honestly — they exist to
    // bound what a script can do, not to pace the simulator.
    Route::post('courses/{course:slug}/challenges/{challenge:slug}/attempts', [ChallengeController::class, 'store'])
        ->middleware('throttle:30,1')
        ->name('challenges.attempts.store');

    /*
     * Choosing an airframe is sold on Pro. The Gate is registered from the
     * Feature enum in AppServiceProvider, so naming the ability here is the
     * whole of the wiring — and it is the only place the choice can be
     * written, which is what keeps a Starter pilot on the stock drone even
     * if they post at this endpoint directly.
     *
     * The throttle is loose because a pilot comparing airframes flips through
     * the fleet, and each flip is one small write.
     */
    Route::put('courses/{course:slug}/challenges/{challenge:slug}/drone', [ChallengeDroneController::class, 'update'])
        ->middleware(['can:drone_config_editor', 'throttle:60,1'])
        ->name('challenges.drone.update');

    // Sized for the busiest mission's photo quota across several runs back to
    // back; the client uploads them one at a time regardless.
    Route::post('courses/{course:slug}/challenges/{challenge:slug}/photos', [DronePhotoController::class, 'store'])
        ->middleware('throttle:120,1')
        ->name('challenges.photos.store');

    Route::get('photos', [DronePhotoController::class, 'index'])->name('photos.index');
    Route::delete('photos/{photo}', [DronePhotoController::class, 'destroy'])->name('photos.destroy');
});
