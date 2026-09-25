<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\SelectMissionDrone;
use App\Http\Requests\UpdateMissionDroneRequest;
use App\Models\Challenge;
use App\Models\Course;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

final class ChallengeDroneController extends Controller
{
    /**
     * Choose the airframe this pilot flies this mission in.
     *
     * The plan checks repeat the pair every challenge endpoint makes, and for
     * the same reason ChallengeController::store gives: nothing stops a client
     * posting straight at this endpoint, and a mission a pilot cannot fly is
     * not one they may configure a drone for. The separate
     * `can:drone_config_editor` middleware on the route is the other half —
     * that one is about the pilot's plan reaching the feature, this is about
     * their plan reaching the mission, and a pilot can have either without
     * the other.
     *
     * Redirects back rather than answering with the drone. The cockpit reads
     * its airframe from the `drone` page prop, so the partial reload the
     * redirect triggers is what actually swaps the drone in the viewport —
     * and it swaps it from server state, which is the only state grading will
     * later agree with.
     */
    public function update(
        UpdateMissionDroneRequest $request,
        #[CurrentUser] User $user,
        Course $course,
        Challenge $challenge,
        SelectMissionDrone $selectDrone,
    ): RedirectResponse {
        abort_unless($challenge->isAvailableIn($course), 404);
        abort_unless($challenge->isUnlockedFor($user, $course), 403);

        $drone = $request->drone();

        $selectDrone->handle($user, $challenge, $drone);

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __(':drone is on the pad.', ['drone' => $drone->name]),
        ]);

        return back();
    }
}
