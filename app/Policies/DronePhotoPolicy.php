<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\DronePhoto;
use App\Models\User;

/**
 * A pilot's photo log is private to that pilot.
 *
 * Photos are only ever reachable by id, so ownership is the whole of the
 * authorization story here; keeping it in a policy means the rule is stated
 * once and enforced by the framework rather than re-derived at each call
 * site.
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
