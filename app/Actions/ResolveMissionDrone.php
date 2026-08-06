<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\Feature;
use App\Models\Challenge;
use App\Models\DroneModel;
use App\Models\User;

/**
 * The airframe a pilot flies a given mission in.
 *
 * The single answer to that question, asked by the page that renders the
 * cockpit and again by the endpoint that grades what the cockpit sent. Both
 * have to agree — a run graded against a drone the pilot was not flying is
 * worse than no attribution at all — and the way to make them agree is for
 * neither to work it out.
 *
 * Nothing here reads the request. The choice lives on the progress row,
 * written only by {@see SelectMissionDrone} behind the Gate, so a pilot
 * cannot post an airframe they are not entitled to fly. That is the same
 * principle StoreChallengeAttemptRequest already holds to: the client
 * describes the flight, the server decides what it was.
 */
final readonly class ResolveMissionDrone
{
    public function __construct(private ResolveFleetDefault $fleetDefault)
    {
        //
    }

    /**
     * Resolve the drone, falling back to the fleet default.
     *
     * The default is returned in three separate situations, and they are not
     * the same situation: the pilot has not chosen, the pilot's plan does not
     * reach the picker, and the drone they once chose has since left the
     * fleet. All three mean "fly the airframe every mission is authored
     * against", which is the honest answer to each.
     *
     * The entitlement is re-checked here rather than trusted from whenever
     * the selection was made. A pilot who chose a Vector on Pro and then
     * lapsed to Starter still has the row; what they no longer have is the
     * feature, and a lapsed subscription that keeps flying the racing quad is
     * a paid capability that never actually ends.
     */
    public function handle(?User $user, Challenge $challenge): DroneModel
    {
        $chosen = $this->chosenBy($user, $challenge);

        return $chosen ?? $this->fleetDefault->handle();
    }

    /**
     * The drone this pilot has actively chosen for this mission, if any.
     */
    private function chosenBy(?User $user, Challenge $challenge): ?DroneModel
    {
        if (! $user instanceof User || ! $user->can(Feature::DroneConfigEditor->value)) {
            return null;
        }

        return DroneModel::query()
            ->whereIn('id', $user->challengeProgress()
                ->whereBelongsTo($challenge)
                ->select('drone_model_id'))
            ->first();
    }
}
