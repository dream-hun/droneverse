<?php

use App\Http\Controllers\ChallengeController;
use App\Http\Controllers\CourseController;
use Illuminate\Support\Facades\Route;

Route::get('courses', [CourseController::class, 'index'])->name('courses.index');
Route::get('courses/{course:slug}', [CourseController::class, 'show'])->name('courses.show');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('courses/{course:slug}/challenges/{challenge:slug}', [ChallengeController::class, 'show'])->name('challenges.show');
    Route::post('courses/{course:slug}/challenges/{challenge:slug}/attempts', [ChallengeController::class, 'store'])->name('challenges.attempts.store');
});
