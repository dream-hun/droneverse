<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\Role;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Define a new staff role and the admin permissions it carries.
 *
 * The permissions are named by App\Enums\AdminPermission values and are found
 * or created as they are granted, so a role saved before anybody has run the
 * seeder on this environment still ends up with real rows behind it.
 */
final readonly class CreateRole
{
    public function __construct(private SyncAdminPermissions $sync) {}

    /**
     * @param  array<int, string>  $permissions
     *
     * @throws Throwable
     */
    public function handle(string $name, array $permissions): Role
    {
        $this->sync->handle();

        return DB::transaction(function () use ($name, $permissions): Role {
            $role = Role::create(['name' => $name]);

            assert($role instanceof Role);

            $role->syncPermissions($permissions);

            return $role;
        });
    }
}
