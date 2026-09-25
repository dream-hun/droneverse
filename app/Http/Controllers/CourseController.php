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
use App\Models\UserChallengeProgress;
use App\Models\UserQuizProgress;
use App\Queries\Leaderboard;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

final class CourseController extends Controller
{
    /**
     * Display a listing of the published courses.
     */
    public function index(Request $request, Leaderboard $leaderboard): Response
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
            'courses' => Inertia::defer(function () use ($user, $leaderboard): array {
                // The catalog is public, so a guest has no progress to merge.
                $completedByCourse = $user instanceof User
                    ? $leaderboard->completedCountsByCourse($user)
                    : new Collection;

                return CourseCatalogResource::collection(
                    Course::catalog()->get(),
                    $completedByCourse,
                    $user?->plan() ?? Plan::Starter,
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
    public function show(Request $request, Course $course, BuildCourseDocumentation $documentation): Response
    {
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
            'challenges' => Inertia::defer(function () use ($course, $user): array {
                $challenges = $course->challenges()
                    ->published()
                    ->get(['id', 'title', 'slug', 'briefing', 'difficulty', 'required_plan']);

                return ChallengeSummaryResource::collection(
                    $challenges,
                    $this->progressByChallenge($user, $challenges->map(fn (Challenge $challenge): int => $challenge->id)),
                    $course,
                    $user?->plan() ?? Plan::Starter,
                );
            }),
            /*
             * Deferred separately from the missions rather than folded in
             * with them. They are two independent lists rendered in two
             * places, and a shared closure would make the missions — which
             * are what the page is for — wait on a count of quiz questions.
             */
            'quizzes' => Inertia::defer(function () use ($course, $user): array {
                $quizzes = $course->quizzes()
                    ->published()
                    ->withCount('questions')
                    ->get(['id', 'title', 'slug', 'description', 'required_plan', 'pass_percentage']);

                return QuizSummaryResource::collection(
                    $quizzes,
                    $this->progressByQuiz($user, $quizzes->map(fn (Quiz $quiz): int => $quiz->id)),
                    $course,
                    $user?->plan() ?? Plan::Starter,
                );
            }),
        ]);
    }

    /**
     * The viewer's progress on the given challenges, keyed by challenge id.
     *
     * One query for the whole page rather than one per row; a guest skips
     * the trip entirely.
     *
     * Only the four columns the rows are rendered from. `last_code` is on
     * this table too, and it is a longText holding the pilot's whole editor
     * buffer — up to twenty kilobytes per mission. Selecting `*` read the
     * saved code for every mission in the course, off disk and into a
     * hydrated model, to render a status badge and a star count. The play
     * page is where saved code is actually wanted, and it asks for one row.
     *
     * @param  Collection<int, int>  $challengeIds
     * @return Collection<int, UserChallengeProgress>
     */
    private function progressByChallenge(?User $user, Collection $challengeIds): Collection
    {
        if (! $user instanceof User || $challengeIds->isEmpty()) {
            return new Collection;
        }

        return $user->challengeProgress()
            ->whereIn('challenge_id', $challengeIds)
            ->get(['challenge_id', 'status', 'best_score', 'stars'])
            ->keyBy(fn (UserChallengeProgress $progress): int => $progress->challenge_id);
    }

    /**
     * The viewer's progress on the given quizzes, keyed by quiz id.
     *
     * One query for the whole page rather than one per row; a guest skips the
     * trip entirely. `passed_at` is selected as well as the counters because
     * {@see UserQuizProgress::status()} derives the row's state from it, and a
     * row missing the column would read as never passed.
     *
     * @param  Collection<int, int>  $quizIds
     * @return Collection<int, UserQuizProgress>
     */
    private function progressByQuiz(?User $user, Collection $quizIds): Collection
    {
        if (! $user instanceof User || $quizIds->isEmpty()) {
            return new Collection;
        }

        return $user->quizProgress()
            ->whereIn('quiz_id', $quizIds)
            ->get(['quiz_id', 'best_score', 'attempts', 'passed_at'])
            ->keyBy(fn (UserQuizProgress $progress): int => $progress->quiz_id);
    }
}
