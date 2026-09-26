<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin;

use App\Enums\AdminPermission;
use App\Models\Role;
use Illuminate\Support\Collection;
use Spatie\Permission\Models\Permission;

/**
 * A staff role as the roles screen lists it.
 *
 * The `admin` role reports every permission, because that is what it holds —
 * through the Gate rather than through rows, so reading its rows would show an
 * empty role that is in fact the most powerful one there is.
 *
 * @phpstan-type Row array{uuid: string, name: string, isAdmin: bool, permissions: array<int, string>, users: int}
 */
final class AdminRoleResource
{
    /**
     * @param  Collection<int, Role>  $roles  with `permissions` loaded and `users` counted
     * @return array<int, Row>
     */
    public static function collection(Collection $roles): array
    {
        return $roles->map(fn (Role $role): array => self::one($role))->values()->all();
    }

    /**
     * @return Row
     */
    public static function one(Role $role): array
    {
        return [
            'uuid' => $role->uuid,
            'name' => $role->name,
            'isAdmin' => $role->isAdmin(),
            'permissions' => $role->isAdmin()
                ? AdminPermission::values()
                : $role->permissions->map(fn (Permission $permission): string => $permission->name)->values()->all(),
            'users' => $role->users_count ?? 0,
        ];
    }
}
