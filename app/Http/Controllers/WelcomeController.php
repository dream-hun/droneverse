<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\ResolveFleetDefault;
use App\Http\Resources\CourseMarketingResource;
use App\Http\Resources\DroneModelResource;
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
     * marketing copy. The drone turning in the hero is the same one, for the
     * same reason: it is the fleet's actual default airframe, read from the
     * catalogue rather than from a copy of its dimensions kept on the client,
     * so a visitor is shown the drone they would be handed on signing up. It
     * is resolved through the same action the cockpit falls back to, which is
     * what makes "the drone they would be handed" a fact rather than a claim.
     */
    public function __invoke(ResolveFleetDefault $fleetDefault): Response
    {
        $courses = CourseMarketingResource::collection(Course::catalog()->get());

        return Inertia::render('welcome', [
            'courses' => $courses,
            'missionCount' => array_sum(array_column($courses, 'challengesCount')),
            'drone' => DroneModelResource::one($fleetDefault->handle()),
        ]);
    }
}
