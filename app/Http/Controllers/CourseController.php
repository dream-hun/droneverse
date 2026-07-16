<?php

namespace App\Http\Controllers;

use App\Enums\ChallengeStatus;
use App\Models\Challenge;
use App\Models\Course;
use App\Models\UserChallengeProgress;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class CourseController extends Controller
{
    /**
     * Display a listing of the published courses.
     */
    public function index(Request $request): Response
    {
        $courses = Course::catalog()->get();

        $completedByCourse = $request->user()
            ? UserChallengeProgress::completedCountsByCourse($request->user())
            : collect();

        return Inertia::render('courses/index', [
            'courses' => $courses->map(fn (Course $course) => [
                'title' => $course->title,
                'slug' => $course->slug,
                'description' => $course->description,
                'difficulty' => $course->difficulty,
                'challengesCount' => $course->challenges_count,
                'completedCount' => $completedByCourse->get($course->id, 0),
            ]),
        ]);
    }

    /**
     * Display the challenges within a course.
     */
    public function show(Request $request, Course $course): Response
    {
        abort_unless($course->is_published, 404);

        $challenges = $course->challenges()
            ->published()
            ->get(['id', 'title', 'slug', 'briefing', 'difficulty']);

        $progressByChallenge = $request->user()
            ?->challengeProgress()
            ->whereIn('challenge_id', $challenges->pluck('id'))
            ->get()
            ->keyBy('challenge_id')
            ?? collect();

        return Inertia::render('courses/show', [
            'course' => [
                'title' => $course->title,
                'slug' => $course->slug,
                'description' => $course->description,
                'difficulty' => $course->difficulty,
            ],
            'challenges' => $challenges->map(function (Challenge $challenge) use ($progressByChallenge) {
                $progress = $progressByChallenge->get($challenge->id);

                return [
                    'title' => $challenge->title,
                    'slug' => $challenge->slug,
                    'briefing' => $challenge->briefing,
                    'difficulty' => $challenge->difficulty,
                    'status' => $progress->status ?? ChallengeStatus::NotStarted,
                    'bestScore' => $progress->best_score ?? 0,
                    'stars' => $progress->stars ?? 0,
                ];
            }),
        ]);
    }
}
