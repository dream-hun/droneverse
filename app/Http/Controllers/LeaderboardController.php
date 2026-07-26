<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Course;
use App\Models\User;
use App\Models\UserChallengeProgress;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
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

        $standings = UserChallengeProgress::standings($user, $course, self::TOP_PILOTS);

        return Inertia::render('leaderboard/index', [
            'courses' => $courses->map(fn (Course $course): array => [
                'title' => $course->title,
                'slug' => $course->slug,
            ])->values(),
            'courseSlug' => $course?->slug,
            'courseTitle' => $course?->title,
            'standings' => $standings,
            'you' => $this->viewerStanding($user, $course, $standings),
            'pilotCount' => UserChallengeProgress::rankedPilotCount($course),
            'topPilots' => self::TOP_PILOTS,
        ]);
    }

    /**
     * The viewer's own row on the board.
     *
     * A listed pilot's row is already in hand and identical to what a fresh
     * lookup would return, so only pilots who placed outside the listed page
     * pay for a second pass over the ranking.
     *
     * @param  Collection<int, array{rank: int, name: string, points: int, stars: int, completed: int, isYou: bool}>  $standings
     * @return array{rank: int, name: string, points: int, stars: int, completed: int, isYou: bool}|null
     */
    private function viewerStanding(User $user, ?Course $course, Collection $standings): ?array
    {
        return $standings->firstWhere('isYou', true)
            ?? UserChallengeProgress::standingFor($user, $course);
    }
}
