<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Resources\CourseMarketingResource;
use App\Models\Course;
use Inertia\Inertia;
use Inertia\Response;

final class WelcomeController extends Controller
{
    /**
     * Show the landing page, advertising the catalog as it actually stands.
     *
     * The course list and the mission count the hero quotes both come from
     * here, so publishing a course changes the pitch without anyone editing
     * marketing copy.
     */
    public function __invoke(): Response
    {
        $courses = CourseMarketingResource::collection(Course::catalog()->get());

        return Inertia::render('welcome', [
            'courses' => $courses,
            'missionCount' => array_sum(array_column($courses, 'challengesCount')),
        ]);
    }
}
