<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\GradeSimulatorRun;
use App\Actions\ReconstructRunTelemetry;
use App\Actions\RecordChallengeAttempt;
use App\Actions\ResolveMissionDrone;
use App\Enums\Feature;
use App\Http\Requests\StoreChallengeAttemptRequest;
use App\Http\Resources\ChallengeDetailResource;
use App\Http\Resources\ChallengeProgressResource;
use App\Http\Resources\ChallengeSolutionResource;
use App\Http\Resources\DroneModelResource;
use App\Models\Challenge;
use App\Models\Course;
use App\Models\DroneModel;
use App\Models\User;
use App\Models\UserChallengeProgress;
use App\Queries\FlightLog;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;
use Inertia\Inertia;
use Inertia\Response;

final class ChallengeController extends Controller
{
    /**
     * Display the simulator for a challenge.
     */
    public function show(
        #[CurrentUser] User $user,
        Course $course,
        Challenge $challenge,
        FlightLog $flightLog,
        ResolveMissionDrone $resolveDrone,
    ): Response {
        abort_unless($challenge->isAvailableIn($course), 404);
        abort_unless($challenge->isUnlockedFor($user, $course), 403);

        $progress = $this->progressFor($user, $challenge);
        $canConfigureDrone = $user->can(Feature::DroneConfigEditor->value);

        return Inertia::render('challenges/play', [
            'course' => [
                'title' => $course->title,
                'slug' => $course->slug,
            ],
            'challenge' => ChallengeDetailResource::one($challenge),
            'progress' => ChallengeProgressResource::one($challenge, $progress),
            'solution' => ChallengeSolutionResource::one($challenge, $progress),
            /*
             * The airframe this pilot is about to fly. Always sent, for every
             * pilot on every plan — the simulator cannot draw a drone or
             * command one without it, and a Starter pilot flies the fleet
             * default rather than nothing.
             */
            'drone' => DroneModelResource::one($resolveDrone->handle($user, $challenge)),
            /*
             * The rest of the fleet is only for pilots who may actually
             * choose from it. Null is what tells the cockpit to render the
             * upgrade prompt in the picker's place, and it means the query
             * is not run for the pilots who would only be shown that.
             */
            'fleet' => $canConfigureDrone
                ? DroneModelResource::collection(DroneModel::query()->inFleetOrder()->get())
                : null,
            /*
             * The pilot's history on this mission, for the Pro analytics
             * panel. Deferred because the simulator is what this page is for
             * and it should not wait on two aggregates to become playable,
             * and resolved only for pilots the Gate allows — a Starter pilot
             * never pays a query for a panel they will not be shown.
             */
            'flightLog' => $user->can(Feature::AdvancedAnalytics->value)
                ? Inertia::defer(fn (): array => [
                    'curve' => $flightLog->missionCurve($user, $challenge),
                    'cohort' => $flightLog->cohortFor($user, $challenge),
                ])
                : null,
        ]);
    }

    /**
     * Grade and record a simulator run.
     *
     * The submission describes the flight, not its result: the objectives
     * are measured against the mission's own geometry and scored here, so a
     * pilot cannot post themselves a perfect run. The graded outcome comes
     * back in the response, which is what the pilot is finally shown.
     *
     * The plan check is repeated here rather than left to the page that
     * normally precedes it. Nothing stops a client posting straight at this
     * endpoint, and a locked mission that still accepts attempts is not
     * locked — it just has no link.
     *
     * The airframe is resolved from the pilot's saved selection rather than
     * read off the submission, for the same reason the score is. A run
     * carrying its own drone would let any client claim a Vector's envelope
     * on a mission the pilot flew a Cadet through — and the attribution
     * exists precisely so the analytics record can be trusted about which
     * airframe flew which curve.
     */
    public function store(
        StoreChallengeAttemptRequest $request,
        #[CurrentUser] User $user,
        Course $course,
        Challenge $challenge,
        ReconstructRunTelemetry $reconstruct,
        GradeSimulatorRun $grade,
        RecordChallengeAttempt $recordAttempt,
        ResolveMissionDrone $resolveDrone,
    ): JsonResponse {
        abort_unless($challenge->isAvailableIn($course), 404);
        abort_unless($challenge->isUnlockedFor($user, $course), 403);

        $run = $request->run();
        $result = $grade->handle($reconstruct->handle($challenge, $run), $challenge);
        $drone = $resolveDrone->handle($user, $challenge);

        $progress = $recordAttempt->handle($user, $challenge, $result, $run['code'], $drone);

        return response()->json([
            'result' => $result,
            'progress' => [
                'status' => $progress->status,
                'bestScore' => $progress->best_score,
                'stars' => $progress->stars,
                'attempts' => $progress->attempts,
            ],
        ]);
    }

    /**
     * The viewer's progress row for this mission, if they have flown it.
     */
    private function progressFor(User $user, Challenge $challenge): ?UserChallengeProgress
    {
        return $user->challengeProgress()
            ->whereBelongsTo($challenge)
            ->first();
    }
}
