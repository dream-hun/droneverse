<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\AdminPermission;
use App\Models\Role;
use App\Models\User;

/**
 * What one member of staff may do to another person's account.
 *
 * `manage_users` opens the users screen; it does not make every account on it
 * fair game. Three rules sit on top, and each closes a way for a narrower role
 * to become a wider one.
 *
 * An account holding any role is staff, and only an admin may edit or delete
 * staff. Without that, a support agent could change an admin's email to their
 * own, reset the password, and be the admin — the edit form is a takeover
 * form when it is pointed at someone more trusted than the person using it.
 *
 * Handing out roles needs `manage_roles` as well, and a role can only be
 * handed out by somebody who already holds everything it grants — the rule
 * App\Policies\RolePolicy applies to editing one. Otherwise the users screen
 * would be a quieter route to exactly what the roles screen guards. `admin`
 * grants everything, so in practice only an admin can hand that one out.
 *
 * Nobody deletes their own account from here, and an admin cannot take their
 * own admin role away. Settings is where an account deletes itself, with its
 * password; and since only admins act on admins, an admin who cannot demote
 * themselves is also an admin who cannot leave the business with none.
 */
final class UserPolicy
{
    public function update(User $actor, User $user): bool
    {
        return $this->isAdmin($actor) || ! $this->isStaff($user);
    }

    public function delete(User $actor, User $user): bool
    {
        return $actor->isNot($user) && $this->update($actor, $user);
    }

    public function assignRoles(User $actor): bool
    {
        return $actor->can(AdminPermission::ManageRoles->value);
    }

    public function assignAdminRole(User $actor): bool
    {
        return $this->isAdmin($actor);
    }

    public function assignRole(User $actor, Role $role): bool
    {
        return $this->assignRoles($actor) && $role->isWithinReachOf($actor);
    }

    private function isAdmin(User $user): bool
    {
        return $user->hasRole(Role::ADMIN);
    }

    /**
     * Read from the loaded relation when there is one, so the user list can
     * ask this of every row on a page without a query per row.
     */
    private function isStaff(User $user): bool
    {
        return $user->relationLoaded('roles')
            ? $user->roles->isNotEmpty()
            : $user->roles()->exists();
    }
}
