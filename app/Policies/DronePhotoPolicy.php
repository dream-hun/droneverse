<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\DronePhoto;
use App\Models\User;

/**
 * A pilot's photo log is private to that pilot.
 *
 * A photo is reachable by nothing but its own identifier, so ownership is the
 * whole of the authorization story here; keeping it in a policy means the rule
 * is stated once and enforced by the framework rather than re-derived at each
 * call site.
 *
 * The uuid route key is not part of that story and does not weaken it. This
 * check is what stops a pilot reaching another's photo; the uuid only stops
 * the attempt from being worth making.
 */
final class DronePhotoPolicy
{
    public function view(User $user, DronePhoto $photo): bool
    {
        return $photo->user_id === $user->id;
    }

    public function delete(User $user, DronePhoto $photo): bool
    {
        return $photo->user_id === $user->id;
    }
}
