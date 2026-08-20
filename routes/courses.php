<?php

declare(strict_types=1);

use App\Http\Controllers\ChallengeController;
use App\Http\Controllers\ChallengeDroneController;
use App\Http\Controllers\CourseController;
use App\Http\Controllers\CourseDocsController;
use App\Http\Controllers\DronePhotoController;
use App\Http\Controllers\QuizController;
use Illuminate\Support\Facades\Route;

Route::get('courses', [CourseController::class, 'index'])->name('courses.index');
Route::get('courses/{course:slug}', [CourseController::class, 'show'])->name('courses.show');

/*
 * The written guide behind a course: what it teaches, the drone commands it
 * is built on, and worked examples a pilot can copy into the editor.
 *
 * Public, and deliberately so. It sits beside the catalog rather than behind
 * the auth wall because it is part of the same argument the course page
 * makes — and because the examples are authored for the documentation, not
 * lifted from the missions, so an open page gives away no reference solution.
 * Courses whose guide has not been written 404 here; see
 * App\Actions\BuildCourseDocumentation.
 */
Route::get('courses/{course:slug}/docs', CourseDocsController::class)->name('courses.docs');

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

    Route::get('courses/{course:slug}/quizzes/{quiz:slug}', [QuizController::class, 'show'])->name('quizzes.show');

    /*
     * A quiz is answered once and submitted once, so this ceiling sits far
     * above any pilot taking one honestly — it exists to bound what a script
     * can do, not to pace a retake. Retakes are unlimited by design and a
     * pilot reviewing a quiz may well submit it several times in a sitting,
     * which is why it is not tighter.
     */
    Route::post('courses/{course:slug}/quizzes/{quiz:slug}/attempts', [QuizController::class, 'store'])
        ->middleware('throttle:30,1')
        ->name('quizzes.attempts.store');

    Route::get('photos', [DronePhotoController::class, 'index'])->name('photos.index');
    Route::delete('photos/{photo}', [DronePhotoController::class, 'destroy'])->name('photos.destroy');
});
