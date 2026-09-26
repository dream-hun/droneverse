<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\AdminPermission;
use App\Models\Role;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Throwable;

/**
 * Bring the permissions table in line with App\Enums\AdminPermission.
 *
 * The enum is the vocabulary and the table is its copy, so this writes a row
 * for every case and removes any row naming a case that no longer exists. A
 * stale row would otherwise stay ticked on roles, granting nothing and
 * claiming to — which is the one thing a permission screen must never do.
 *
 * It also makes sure the `admin` role exists. That role needs no permission
 * rows of its own, because the Gate hook in AppServiceProvider grants it
 * everything, but it does need to be there to be handed to somebody.
 *
 * Idempotent, so it is safe on every deploy and every seed.
 */
final readonly class SyncAdminPermissions
{
    public function __construct(private PermissionRegistrar $registrar) {}

    /**
     * @throws Throwable
     */
    public function handle(): Role
    {
        $role = DB::transaction(function (): Role {
            foreach (AdminPermission::cases() as $permission) {
                Permission::findOrCreate($permission->value);
            }

            Permission::query()->whereNotIn('name', AdminPermission::values())->delete();

            $admin = Role::findOrCreate(Role::ADMIN);

            assert($admin instanceof Role);

            return $admin;
        });

        $this->registrar->forgetCachedPermissions();

        return $role;
    }
}
