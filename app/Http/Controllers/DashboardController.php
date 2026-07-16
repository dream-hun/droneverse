<?php

namespace App\Http\Controllers;

use App\Enums\ChallengeStatus;
use App\Models\Course;
use App\Models\UserChallengeProgress;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    /**
     * Handle the incoming request.
     */
    public function __invoke(Request $request): Response
    {
        $user = $request->user();

        $courses = Course::catalog()->get();
        $completedByCourse = UserChallengeProgress::completedCountsByCourse($user);

        $continue = $user->challengeProgress()
            ->with('challenge.course')
            ->where('status', '!=', ChallengeStatus::Completed)
            ->latest('updated_at')
            ->first();

        return Inertia::render('dashboard', [
            'courses' => $courses->map(fn (Course $course) => [
                'title' => $course->title,
                'slug' => $course->slug,
                'difficulty' => $course->difficulty,
                'challengesCount' => $course->challenges_count,
                'completedCount' => $completedByCourse->get($course->id, 0),
            ]),
            'continue' => $continue && $continue->challenge ? [
                'courseSlug' => $continue->challenge->course->slug,
                'challengeSlug' => $continue->challenge->slug,
                'challengeTitle' => $continue->challenge->title,
            ] : null,
            'stats' => UserChallengeProgress::statsFor($user),
        ]);
    }
}
