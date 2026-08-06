<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\ResolveFleetDefault;
use App\Http\Resources\CourseMarketingResource;
use App\Http\Resources\DroneModelResource;
use App\Models\Course;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

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
            'drone' => $this->heroDrone($fleetDefault),
        ]);
    }

    /**
     * The drone turning in the hero, or nothing if the fleet cannot name one.
     *
     * The only caller of {@see ResolveFleetDefault} that survives its own
     * failure, and the asymmetry is the point. A cockpit with no airframe has
     * nothing to render and nothing to fly, so the mission page is right to
     * fail; the hero drone is decoration on a page whose job is to explain the
     * product and take a signup, and none of the headline, the copy, the
     * course list or the call to action depends on it. Taking the storefront
     * down over an ornament costs signups to fix nothing.
     *
     * Reported rather than swallowed, because a fleet that cannot name a
     * default is still a broken deploy — it is just not the visitor's problem.
     * The alarm belongs in the logs, where someone can act on it, instead of
     * on a stranger's screen.
     *
     * @return array{id: string, slug: string, name: string, class: string, classLabel: string, summary: string, isDefault: bool, flight: array<string, float>, airframe: array<string, float|int|string>}|null
     */
    private function heroDrone(ResolveFleetDefault $fleetDefault): ?array
    {
        try {
            return DroneModelResource::one($fleetDefault->handle());
        } catch (RuntimeException $e) {
            report($e);

            return null;
        }
    }
}
