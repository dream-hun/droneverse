<?php

declare(strict_types=1);

use App\Http\Controllers\ChallengeController;
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

    // Sized for the busiest mission's photo quota across several runs back to
    // back; the client uploads them one at a time regardless.
    Route::post('courses/{course:slug}/challenges/{challenge:slug}/photos', [DronePhotoController::class, 'store'])
        ->middleware('throttle:120,1')
        ->name('challenges.photos.store');

    Route::get('photos', [DronePhotoController::class, 'index'])->name('photos.index');
    Route::delete('photos/{photo}', [DronePhotoController::class, 'destroy'])->name('photos.destroy');
});
