<?php

declare(strict_types=1);

namespace App\Http\Controllers;

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
     * Record the result of a simulator run.
     */
    public function store(
        StoreChallengeAttemptRequest $request,
        Course $course,
        Challenge $challenge,
        RecordChallengeAttempt $recordAttempt,
    ): JsonResponse {
        abort_unless($challenge->isPlayableIn($course), 404);

        $progress = $recordAttempt->handle($request->user(), $challenge, $request->attempt());

        return response()->json([
            'status' => $progress->status,
            'bestScore' => $progress->best_score,
            'stars' => $progress->stars,
            'attempts' => $progress->attempts,
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
