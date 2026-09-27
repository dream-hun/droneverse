<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Resources\CourseCardResource;
use App\Models\Course;
use App\Models\User;
use App\Queries\ContentProgress;
use App\Queries\PilotProgress;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class DashboardController extends Controller
{
    /**
     * Handle the incoming request.
     */
    public function __invoke(Request $request, PilotProgress $progress, ContentProgress $contentProgress): Response
    {
        /** @var User $user Route middleware requires an authenticated pilot. */
        $user = $request->user();

        /*
         * The stat tiles and the continue card stay on the initial request:
         * they are the top of the page and they are cheap. Only the course
         * grid below them is deferred, and its queries live inside the closure
         * so the first request does not run them just to throw them away.
         */
        return Inertia::render('dashboard', [
            'courses' => Inertia::defer(fn (): array => CourseCardResource::collection(
                Course::catalog()->get(),
                $progress->completedCountsByCourse($user),
                $user->plan(),
            )),
            'continue' => $this->continueCard($user, $contentProgress),
            'stats' => $progress->statsFor($user),
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
    private function continueCard(User $user, ContentProgress $contentProgress): ?array
    {
        $challenge = $contentProgress->latestInProgress($user)?->challenge;

        if ($challenge === null) {
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
