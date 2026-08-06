<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\Challenge;
use App\Models\DroneModel;
use App\Models\User;
use App\Models\UserChallengeProgress;

/**
 * Record which airframe a pilot intends to fly a mission in.
 *
 * The only writer of `user_challenge_progress.drone_model_id`, and the only
 * place the choice is ever taken from a request. Everything downstream —
 * the cockpit's picker, the grading endpoint's attribution — reads it back
 * through {@see ResolveMissionDrone}, so this is the one point the
 * entitlement has to hold, and it is held by the Gate on the route rather
 * than re-checked here.
 */
final readonly class SelectMissionDrone
{
    /**
     * Save the pilot's drone for this mission.
     *
     * `firstOrCreate` because a pilot may pick their airframe before they
     * have ever flown, which is the normal case and the whole point of
     * choosing one. The row it creates is a progress row at its defaults —
     * `not_started`, no score, no attempts — so a mission somebody has only
     * configured still counts as unflown everywhere progress is read.
     */
    public function handle(User $user, Challenge $challenge, DroneModel $drone): UserChallengeProgress
    {
        $progress = UserChallengeProgress::query()->firstOrCreate([
            'user_id' => $user->id,
            'challenge_id' => $challenge->id,
        ]);

        $progress->drone_model_id = $drone->id;
        $progress->save();

        return $progress;
    }
}
