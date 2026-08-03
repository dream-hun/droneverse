<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\ChallengeStatus;
use App\Http\Resources\CourseCardResource;
use App\Models\Course;
use App\Models\User;
use App\Queries\Leaderboard;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class DashboardController extends Controller
{
    /**
     * Handle the incoming request.
     */
    public function __invoke(Request $request, Leaderboard $leaderboard): Response
    {
        $user = $request->user();

        /*
         * The stat tiles and the continue card stay on the initial request:
         * they are the top of the page and they are cheap. Only the course
         * grid below them is deferred, and its queries live inside the closure
         * so the first request does not run them just to throw them away.
         */
        return Inertia::render('dashboard', [
            'courses' => Inertia::defer(fn () => CourseCardResource::collection(
                Course::catalog()->get(),
                $leaderboard->completedCountsByCourse($user),
                $user->plan(),
            )),
            'continue' => $this->continueCard($user),
            'stats' => $leaderboard->statsFor($user),
        ]);
    }

    /**
     * The "pick up where you left off" card, or null with nothing in flight.
     *
     * The card must never link to content the simulator route would turn away,
     * so only a published challenge in a published course counts — and, since
     * gating landed, only one the pilot's plan still reaches. A pilot who
     * downgrades keeps the progress rows from missions they can no longer fly,
     * and offering to resume one would be a link straight into a 403.
     *
     * @return array{courseSlug: string, challengeSlug: string, challengeTitle: string}|null
     */
    private function continueCard(User $user): ?array
    {
        /*
         * Three narrow rows rather than three wide ones. A challenge carries
         * its environment, success criteria, starter code and solution code
         * — tens of kilobytes of JSON and source per row — and a progress row
         * carries the pilot's saved editor buffer. This card renders two
         * slugs and a title, and needs only the plan columns behind them to
         * decide whether to render at all.
         */
        $progress = $user->challengeProgress()
            ->select(['id', 'challenge_id'])
            ->with(['challenge' => fn ($challenge) => $challenge
                ->select(['id', 'course_id', 'title', 'slug', 'required_plan'])
                ->with(['course' => fn ($course) => $course->select(['id', 'slug', 'required_plan'])]),
            ])
            ->where('status', '!=', ChallengeStatus::Completed)
            ->whereRelation('challenge', 'is_published', true)
            ->whereRelation('challenge.course', 'is_published', true)
            ->latest('updated_at')
            ->first();

        $challenge = $progress?->challenge;

        if ($challenge === null || $challenge->course === null) {
            return null;
        }

        if (! $challenge->isUnlockedFor($user, $challenge->course)) {
            return null;
        }

        return [
            'courseSlug' => $challenge->course->slug,
            'challengeSlug' => $challenge->slug,
            'challengeTitle' => $challenge->title,
        ];
    }
}
