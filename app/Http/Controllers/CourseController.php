<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\Plan;
use App\Http\Resources\ChallengeSummaryResource;
use App\Http\Resources\CourseCatalogResource;
use App\Models\Course;
use App\Models\User;
use App\Models\UserChallengeProgress;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

final class CourseController extends Controller
{
    /**
     * Display a listing of the published courses.
     */
    public function index(Request $request): Response
    {
        $user = $request->user();
        $courses = Course::catalog()->get();

        // The catalog is public, so a guest simply has no progress to merge.
        $completedByCourse = $user instanceof User
            ? UserChallengeProgress::completedCountsByCourse($user)
            : collect();

        return Inertia::render('courses/index', [
            'courses' => CourseCatalogResource::collection(
                $courses,
                $completedByCourse,
                $user?->plan() ?? Plan::Starter,
            ),
        ]);
    }

    /**
     * Display the challenges within a course.
     *
     * Open to every viewer, whatever tier the course sits in. Locked missions
     * are rendered as locked rather than hidden, and the plan check that
     * actually matters lives on the mission routes.
     */
    public function show(Request $request, Course $course): Response
    {
        abort_unless($course->is_published, 404);

        $challenges = $course->challenges()
            ->published()
            ->get(['id', 'title', 'slug', 'briefing', 'difficulty', 'required_plan']);

        return Inertia::render('courses/show', [
            'course' => [
                'title' => $course->title,
                'slug' => $course->slug,
                'description' => $course->description,
                'difficulty' => $course->difficulty,
                'requiredPlan' => $course->requiredPlan()->value,
            ],
            'challenges' => ChallengeSummaryResource::collection(
                $challenges,
                $this->progressByChallenge($request->user(), $challenges->pluck('id')),
                $course,
                $request->user()?->plan() ?? Plan::Starter,
            ),
        ]);
    }

    /**
     * The viewer's progress on the given challenges, keyed by challenge id.
     *
     * One query for the whole page rather than one per row; a guest skips
     * the trip entirely.
     *
     * @param  Collection<int, int>  $challengeIds
     * @return Collection<int, UserChallengeProgress>
     */
    private function progressByChallenge(?User $user, Collection $challengeIds): Collection
    {
        if (! $user instanceof User || $challengeIds->isEmpty()) {
            return collect();
        }

        return $user->challengeProgress()
            ->whereIn('challenge_id', $challengeIds)
            ->get()
            ->keyBy('challenge_id');
    }
}
