<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Role;
use App\Models\User;

/**
 * What one member of staff may do to a staff role.
 *
 * `manage_roles` opens the roles screen, and on its own it would be a way to
 * become anything: tick every box on a role you hold and save. The rule that
 * closes that is "you can only hand out what you already have". Somebody who
 * is not an admin may edit or delete a role only when everything it grants is
 * something they can already do, and — in App\Http\Requests\Admin\
 * SaveRoleRequest — may only tick permissions they hold themselves.
 *
 * An admin can do all of it, because an admin already holds everything; the
 * same rule, applied, lets them. The `admin` role itself is edited by nobody.
 */
final class RolePolicy
{
    public function update(User $actor, Role $role): bool
    {
        return ! $role->isAdmin() && $role->isWithinReachOf($actor);
    }

    public function delete(User $actor, Role $role): bool
    {
        return $this->update($actor, $role);
    }
}
