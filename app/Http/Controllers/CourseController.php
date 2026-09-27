<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\BuildCourseDocumentation;
use App\Enums\Plan;
use App\Http\Resources\ChallengeSummaryResource;
use App\Http\Resources\CourseCatalogResource;
use App\Http\Resources\QuizSummaryResource;
use App\Models\Challenge;
use App\Models\Course;
use App\Models\Quiz;
use App\Models\User;
use App\Queries\ContentProgress;
use App\Queries\PilotProgress;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

final class CourseController extends Controller
{
    /**
     * Display a listing of the published courses.
     */
    public function index(Request $request, PilotProgress $progress): Response
    {
        $user = $request->user();

        /*
         * Deferred, so the shell and its skeleton render before the catalog is
         * queried. The queries belong inside the closure rather than above it:
         * hoisting them would run the catalog and the progress rollup on the
         * initial request as well as the follow-up, paying for the work twice
         * to display it once.
         */
        return Inertia::render('courses/index', [
            'courses' => Inertia::defer(function () use ($user, $progress): array {
                // The catalog is public, so a guest has no progress to merge.
                $completedByCourse = $user instanceof User
                    ? $progress->completedCountsByCourse($user)
                    : new Collection;

                return CourseCatalogResource::collection(
                    Course::catalog()->get(),
                    $completedByCourse,
                    Plan::forViewer($user),
                );
            }),
        ]);
    }

    /**
     * Display the challenges within a course.
     *
     * Open to every viewer, whatever tier the course sits in. Locked missions
     * are rendered as locked rather than hidden, and the plan check that
     * actually matters lives on the mission routes.
     */
    public function show(
        Request $request,
        Course $course,
        BuildCourseDocumentation $documentation,
        ContentProgress $contentProgress,
    ): Response {
        // Stays on the initial request: a 404 is the whole response, not a
        // section of it, and deferring it would render a page header for a
        // course that does not exist before taking it away again.
        abort_unless($course->is_published, 404);

        $user = $request->user();

        return Inertia::render('courses/show', [
            'course' => [
                'title' => $course->title,
                'slug' => $course->slug,
                'description' => $course->description,
                'difficulty' => $course->difficulty,
                'requiredPlan' => $course->requiredPlan()->value,
            ],
            /*
             * Whether there is a written guide to link to. A config lookup,
             * so it stays on the initial request with the header it belongs
             * to rather than deferring alongside the queries below.
             *
             * Asked rather than assumed: a course is a row and can be created
             * long before anyone writes its documentation, and a link that
             * 404s teaches a pilot to distrust the rest of the page.
             */
            'hasDocs' => $documentation->existsFor($course),
            /*
             * The header renders from the course row the route already loaded;
             * only the mission list waits. Both queries sit inside the closure
             * so the initial request does neither.
             */
            'challenges' => Inertia::defer(function () use ($course, $user, $contentProgress): array {
                $challenges = $course->challenges()
                    ->published()
                    ->get(['id', 'title', 'slug', 'briefing', 'difficulty', 'required_plan']);

                return ChallengeSummaryResource::collection(
                    $challenges,
                    $contentProgress->byChallenge($user, $challenges->map(fn (Challenge $challenge): int => $challenge->id)),
                    $course,
                    Plan::forViewer($user),
                );
            }),
            /*
             * Deferred separately from the missions rather than folded in
             * with them. They are two independent lists rendered in two
             * places, and a shared closure would make the missions — which
             * are what the page is for — wait on a count of quiz questions.
             */
            'quizzes' => Inertia::defer(function () use ($course, $user, $contentProgress): array {
                $quizzes = $course->quizzes()
                    ->published()
                    ->withCount('questions')
                    ->get(['id', 'title', 'slug', 'description', 'required_plan', 'pass_percentage']);

                return QuizSummaryResource::collection(
                    $quizzes,
                    $contentProgress->byQuiz($user, $quizzes->map(fn (Quiz $quiz): int => $quiz->id)),
                    $course,
                    Plan::forViewer($user),
                );
            }),
        ]);
    }
}
