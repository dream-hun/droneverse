<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\Role;

/**
 * Retire a staff role.
 *
 * The accounts holding it keep everything else about themselves and simply
 * stop holding it; the pivot rows cascade. `admin` is refused for the reason
 * UpdateRole gives, and for a plainer one — it is the role that lets someone
 * reach this screen to undo a mistake.
 */
final readonly class DeleteRole
{
    /**
     * False when the role is `admin`, and nothing was deleted.
     */
    public function handle(Role $role): bool
    {
        if ($role->isAdmin()) {
            return false;
        }

        $role->delete();

        return true;
    }
}
