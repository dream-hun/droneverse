<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\BuildCourseDocumentation;
use App\Models\Challenge;
use App\Models\Course;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class CourseDocsController extends Controller
{
    /**
     * Show the written guide for a course.
     *
     * Public, for the same reason {@see CourseController::show()} is: the
     * argument for paying is that there is something here worth flying, and
     * a reference that only a subscriber can read cannot make it. Nothing on
     * this page is gated content — the worked examples are written for the
     * documentation rather than lifted from the missions, so publishing them
     * does not hand anybody a reference solution they have not earned.
     *
     * A course with no authored guide 404s rather than rendering an empty
     * shell, and the course page only offers the link when there is one.
     */
    public function __invoke(Request $request, Course $course, BuildCourseDocumentation $documentation): Response
    {
        abort_unless($course->is_published, 404);

        $documented = $documentation->handle($course);

        abort_if($documented === null, 404);

        return Inertia::render('courses/docs', [
            'course' => [
                'title' => $course->title,
                'slug' => $course->slug,
                'description' => $course->description,
                'difficulty' => $course->difficulty,
                'requiredPlan' => $course->requiredPlan()->value,
            ],
            /*
             * Eager: every word of it comes out of config, so there is no
             * query to defer and deferring it would only cost the page a
             * second round trip to render text it already had.
             */
            'documentation' => $documented,
            /*
             * The one part of this page that touches the database, so it is
             * the one part that waits. A name, a slug and whether this viewer
             * can reach it — the guide links to the missions, it does not
             * restate them, and progress belongs on the course page.
             *
             * `locked` is carried for the same reason ChallengeRow carries
             * it: a locked mission is listed but is not a link, because a
             * link that 403s reads as a bug rather than as a paywall. A guest
             * is Starter here, exactly as they are everywhere else.
             */
            'missions' => Inertia::defer(fn (): array => $course->challenges()
                ->published()
                ->get(['title', 'slug', 'required_plan', 'course_id', 'is_published'])
                ->map(fn (Challenge $challenge): array => [
                    'title' => $challenge->title,
                    'slug' => $challenge->slug,
                    'locked' => ! $challenge->isUnlockedFor($request->user(), $course),
                ])
                ->all()),
        ]);
    }
}
