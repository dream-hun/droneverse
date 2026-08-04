<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\GradeSimulatorRun;
use App\Actions\ReconstructRunTelemetry;
use App\Actions\RecordChallengeAttempt;
use App\Enums\Feature;
use App\Http\Requests\StoreChallengeAttemptRequest;
use App\Http\Resources\ChallengeDetailResource;
use App\Http\Resources\ChallengeProgressResource;
use App\Http\Resources\ChallengeSolutionResource;
use App\Models\Challenge;
use App\Models\Course;
use App\Models\User;
use App\Models\UserChallengeProgress;
use App\Queries\FlightLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class ChallengeController extends Controller
{
    /**
     * Display the simulator for a challenge.
     */
    public function show(Request $request, Course $course, Challenge $challenge, FlightLog $flightLog): Response
    {
        abort_unless($challenge->isPlayableIn($course), 404);
        abort_unless($challenge->isUnlockedFor($request->user(), $course), 403);

        $user = $request->user();
        $progress = $this->progressFor($user, $challenge);

        return Inertia::render('challenges/play', [
            'course' => [
                'title' => $course->title,
                'slug' => $course->slug,
            ],
            'challenge' => ChallengeDetailResource::one($challenge),
            'progress' => ChallengeProgressResource::one($challenge, $progress),
            'solution' => ChallengeSolutionResource::one($challenge, $progress),
            /*
             * The pilot's history on this mission, for the Pro analytics
             * panel. Deferred because the simulator is what this page is for
             * and it should not wait on two aggregates to become playable,
             * and resolved only for pilots the Gate allows — a Starter pilot
             * never pays a query for a panel they will not be shown.
             */
            'flightLog' => $user->can(Feature::AdvancedAnalytics->value)
                ? Inertia::defer(fn (): array => [
                    'curve' => $flightLog->missionCurve($user, $challenge),
                    'cohort' => $flightLog->cohortFor($user, $challenge),
                ])
                : null,
        ]);
    }

    /**
     * Grade and record a simulator run.
     *
     * The submission describes the flight, not its result: the objectives
     * are measured against the mission's own geometry and scored here, so a
     * pilot cannot post themselves a perfect run. The graded outcome comes
     * back in the response, which is what the pilot is finally shown.
     *
     * The plan check is repeated here rather than left to the page that
     * normally precedes it. Nothing stops a client posting straight at this
     * endpoint, and a locked mission that still accepts attempts is not
     * locked — it just has no link.
     */
    public function store(
        StoreChallengeAttemptRequest $request,
        Course $course,
        Challenge $challenge,
        ReconstructRunTelemetry $reconstruct,
        GradeSimulatorRun $grade,
        RecordChallengeAttempt $recordAttempt,
    ): JsonResponse {
        abort_unless($challenge->isPlayableIn($course), 404);
        abort_unless($challenge->isUnlockedFor($request->user(), $course), 403);

        $run = $request->run();
        $result = $grade->handle($reconstruct->handle($challenge, $run), $challenge);

        $progress = $recordAttempt->handle($request->user(), $challenge, $result, $run['code']);

        return response()->json([
            'result' => $result,
            'progress' => [
                'status' => $progress->status,
                'bestScore' => $progress->best_score,
                'stars' => $progress->stars,
                'attempts' => $progress->attempts,
            ],
        ]);
    }

    /**
     * The viewer's progress row for this mission, if they have flown it.
     */
    private function progressFor(?User $user, Challenge $challenge): ?UserChallengeProgress
    {
        return $user?->challengeProgress()
            ->whereBelongsTo($challenge)
            ->first();
    }
}
