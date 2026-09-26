<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin;

use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * A staff role as the roles screen lists it.
 *
 * The `admin` role reports every permission, because that is what it holds;
 * see App\Models\Role::permissionNames(). `can` is the viewer's standing
 * against the role from App\Policies\RolePolicy, so the row menu offers only
 * what the server would allow.
 *
 * @phpstan-type Row array{uuid: string, name: string, isAdmin: bool, permissions: array<int, string>, users: int, can: array{update: bool, delete: bool}}
 */
final class AdminRoleResource
{
    /**
     * @param  Collection<int, Role>  $roles  with `permissions` loaded and `users` counted
     * @return array<int, Row>
     */
    public static function collection(Collection $roles, User $viewer): array
    {
        return $roles->map(fn (Role $role): array => self::one($role, $viewer))->values()->all();
    }

    /**
     * @return Row
     */
    public static function one(Role $role, User $viewer): array
    {
        return [
            'uuid' => $role->uuid,
            'name' => $role->name,
            'isAdmin' => $role->isAdmin(),
            'permissions' => $role->permissionNames(),
            'users' => $role->users_count ?? 0,
            'can' => [
                'update' => $viewer->can('update', $role),
                'delete' => $viewer->can('delete', $role),
            ],
        ];
    }
}
