<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\GradeSimulatorRun;
use App\Actions\ReconstructRunTelemetry;
use App\Actions\RecordChallengeAttempt;
use App\Http\Requests\StoreChallengeAttemptRequest;
use App\Http\Resources\ChallengeDetailResource;
use App\Http\Resources\ChallengeProgressResource;
use App\Http\Resources\ChallengeSolutionResource;
use App\Models\Challenge;
use App\Models\Course;
use App\Models\User;
use App\Models\UserChallengeProgress;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class ChallengeController extends Controller
{
    /**
     * Display the simulator for a challenge.
     */
    public function show(Request $request, Course $course, Challenge $challenge): Response
    {
        abort_unless($challenge->isPlayableIn($course), 404);
        abort_unless($challenge->isUnlockedFor($request->user(), $course), 403);

        $progress = $this->progressFor($request->user(), $challenge);

        return Inertia::render('challenges/play', [
            'course' => [
                'title' => $course->title,
                'slug' => $course->slug,
            ],
            'challenge' => ChallengeDetailResource::one($challenge),
            'progress' => ChallengeProgressResource::one($challenge, $progress),
            'solution' => ChallengeSolutionResource::one($challenge, $progress),
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

        $progress = $recordAttempt->handle($request->user(), $challenge, [
            'score' => $result['score'],
            'stars' => $result['stars'],
            'completed' => $result['completed'],
            'code' => $run['code'],
        ]);

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
