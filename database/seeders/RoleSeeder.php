<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Actions\SyncAdminPermissions;
use App\Enums\AdminPermission;
use App\Models\Role;
use Illuminate\Database\Seeder;
use Spatie\Permission\PermissionRegistrar;
use Throwable;

final class RoleSeeder extends Seeder
{
    /**
     * The staff roles a fresh install starts with.
     *
     * A starting point rather than a policy: every one of these can be edited
     * or deleted from the admin area, and more can be added there. Only
     * `admin` is fixed. Each role is written only if it is missing, so a
     * release never overwrites what somebody has since changed about it.
     *
     * No account is given a role here, for the reason DatabaseSeeder gives for
     * creating none: the first admin is named deliberately, with
     * `php artisan admin:grant`.
     *
     * @throws Throwable
     */
    public function run(SyncAdminPermissions $sync, PermissionRegistrar $registrar): void
    {
        $sync->handle();

        foreach ($this->roles() as $name => $permissions) {
            if (Role::query()->where('name', $name)->exists()) {
                continue;
            }

            $role = Role::findOrCreate($name);
            $role->syncPermissions(array_map(
                static fn (AdminPermission $permission): string => $permission->value,
                $permissions,
            ));
        }

        // DatabaseSeeder runs without model events, which is what the
        // package listens on to drop its cache. Say so explicitly instead.
        $registrar->forgetCachedPermissions();
    }

    /**
     * @return array<string, array<int, AdminPermission>>
     */
    private function roles(): array
    {
        return [
            'content-manager' => [AdminPermission::AccessAdmin, AdminPermission::ManageCourses],
            'support' => [AdminPermission::AccessAdmin, AdminPermission::ManageUsers],
            'finance' => [AdminPermission::AccessAdmin, AdminPermission::ViewFinance],
        ];
    }
}
