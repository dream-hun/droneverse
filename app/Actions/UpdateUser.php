<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Change an account's details, hand-set plan and roles from the admin area.
 *
 * A changed address loses its verification exactly as it does when the pilot
 * changes it themselves in Settings. Staff typing an address are no better
 * placed than anyone else to say the pilot receives mail there, and if they
 * are, marking it verified is a separate, deliberate step.
 *
 * Roles are only touched when they were sent. Somebody holding
 * `manage_users` without `manage_roles` edits the rest of an account with the
 * role field absent, and an absent field has to mean "leave these alone"
 * rather than "take them all away".
 */
final readonly class UpdateUser
{
    /**
     * @param  array{name: string, email: string, plan_override: string|null, roles?: array<int, string>}  $attributes
     *
     * @throws Throwable
     */
    public function handle(User $user, array $attributes): User
    {
        return DB::transaction(function () use ($user, $attributes): User {
            $user->fill([
                'name' => $attributes['name'],
                'email' => $attributes['email'],
                'plan_override' => $attributes['plan_override'],
            ]);

            if ($user->isDirty('email')) {
                $user->email_verified_at = null;
            }

            $user->save();

            if (isset($attributes['roles'])) {
                $user->syncRoles($attributes['roles']);
            }

            return $user->forgetPlan();
        });
    }
}
