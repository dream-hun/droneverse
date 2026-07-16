<?php

namespace App\Http\Controllers;

use App\Actions\RecordChallengeAttempt;
use App\Enums\ChallengeStatus;
use App\Http\Requests\StoreChallengeAttemptRequest;
use App\Models\Challenge;
use App\Models\Course;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ChallengeController extends Controller
{
    /**
     * Display the simulator for a challenge.
     */
    public function show(Request $request, Course $course, Challenge $challenge): Response
    {
        abort_unless($course->is_published && $challenge->is_published, 404);
        abort_unless($challenge->course_id === $course->id, 404);

        $progress = $request->user()
            ->challengeProgress()
            ->whereBelongsTo($challenge)
            ->first();

        return Inertia::render('challenges/play', [
            'course' => [
                'title' => $course->title,
                'slug' => $course->slug,
            ],
            'challenge' => [
                'title' => $challenge->title,
                'slug' => $challenge->slug,
                'briefing' => $challenge->briefing,
                'difficulty' => $challenge->difficulty,
                'environment' => $challenge->environment,
                'successCriteria' => $challenge->success_criteria,
                'maxScore' => $challenge->max_score,
                'starterCode' => $challenge->starter_code,
            ],
            'progress' => [
                'status' => $progress->status ?? ChallengeStatus::NotStarted,
                'bestScore' => $progress->best_score ?? 0,
                'stars' => $progress->stars ?? 0,
                'attempts' => $progress->attempts ?? 0,
                'savedCode' => $progress->last_code ?? $challenge->starter_code,
            ],
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
        abort_unless($course->is_published && $challenge->is_published, 404);
        abort_unless($challenge->course_id === $course->id, 404);

        $progress = $recordAttempt->handle($request->user(), $challenge, $request->attempt());

        return response()->json([
            'status' => $progress->status,
            'bestScore' => $progress->best_score,
            'stars' => $progress->stars,
            'attempts' => $progress->attempts,
        ]);
    }
}
