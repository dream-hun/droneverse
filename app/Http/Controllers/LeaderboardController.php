<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Course;
use App\Models\UserChallengeProgress;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class LeaderboardController extends Controller
{
    /**
     * Pilots listed before the board is cut off.
     */
    public const int TOP_PILOTS = 25;

    /**
     * Display the ranked pilot standings.
     */
    public function __invoke(Request $request): Response
    {
        $user = $request->user();

        $courses = Course::published()->orderBy('order')->get(['id', 'title', 'slug']);

        // An unknown or unpublished slug falls back to the overall board
        // rather than 404ing, so an old bookmark still shows something.
        $course = $courses->firstWhere('slug', $request->query('course'));

        return Inertia::render('leaderboard/index', [
            'courses' => $courses->map(fn (Course $course): array => [
                'title' => $course->title,
                'slug' => $course->slug,
            ])->values(),
            'courseSlug' => $course?->slug,
            'courseTitle' => $course?->title,
            'standings' => UserChallengeProgress::standings($user, $course, self::TOP_PILOTS),
            'you' => UserChallengeProgress::standingFor($user, $course),
            'pilotCount' => UserChallengeProgress::rankedPilotCount($course),
            'topPilots' => self::TOP_PILOTS,
        ]);
    }
}
