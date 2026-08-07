<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\GradeQuizSubmission;
use App\Actions\RecordQuizAttempt;
use App\Http\Requests\StoreQuizAttemptRequest;
use App\Http\Resources\QuizDetailResource;
use App\Http\Resources\QuizProgressResource;
use App\Http\Resources\QuizResultResource;
use App\Models\Course;
use App\Models\Quiz;
use App\Models\User;
use App\Models\UserQuizProgress;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class QuizController extends Controller
{
    /**
     * Display a quiz for the pilot to take.
     */
    public function show(Request $request, Course $course, Quiz $quiz): Response
    {
        abort_unless($quiz->isAvailableIn($course), 404);
        abort_unless($quiz->isUnlockedFor($request->user(), $course), 403);

        return Inertia::render('quizzes/show', [
            'course' => [
                'title' => $course->title,
                'slug' => $course->slug,
            ],
            /*
             * Eager, not deferred, unlike the lists on the catalog pages. The
             * questions are the page — there is no shell worth rendering
             * without them, and deferring would show a pilot a quiz header
             * with a skeleton where the thing they came to do belongs.
             */
            'quiz' => QuizDetailResource::one($quiz),
            'progress' => QuizProgressResource::one($this->progressFor($request->user(), $quiz)),
        ]);
    }

    /**
     * Grade and record a submission.
     *
     * The submission describes what the pilot chose, not how they did: the
     * answers are measured against the quiz's own key and scored here, so a
     * client cannot post itself a pass. The graded outcome comes back in the
     * response, which is what the pilot is finally shown — and it is the only
     * moment the key travels at all.
     *
     * Both guards are repeated here rather than left to the page that
     * normally precedes it, for the same reason the mission attempt endpoint
     * repeats them: nothing stops a client posting straight at this endpoint,
     * and a locked quiz that still accepts submissions is not locked — it
     * just has no link.
     */
    public function store(
        StoreQuizAttemptRequest $request,
        Course $course,
        Quiz $quiz,
        GradeQuizSubmission $grade,
        RecordQuizAttempt $recordAttempt,
    ): JsonResponse {
        abort_unless($quiz->isAvailableIn($course), 404);
        abort_unless($quiz->isUnlockedFor($request->user(), $course), 403);

        /** @var User $user */
        $user = $request->user();

        $result = $grade->handle($quiz, $request->answers());
        $progress = $recordAttempt->handle($user, $quiz, $result);

        return response()->json(QuizResultResource::one($result, $progress));
    }

    /**
     * The viewer's progress row for this quiz, if they have taken it.
     */
    private function progressFor(?User $user, Quiz $quiz): ?UserQuizProgress
    {
        return $user?->quizProgress()
            ->whereBelongsTo($quiz)
            ->first();
    }
}
