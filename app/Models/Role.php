<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AdminPermission;
use Database\Factories\RoleFactory;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Support\Carbon;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role as SpatieRole;

/**
 * A named set of admin permissions, held by members of staff.
 *
 * The package's model with a uuid added, because a role is managed from the
 * admin area and nothing in this application is addressed by its id there.
 *
 * One role is special. `admin` holds every admin permission whatever its rows
 * say — see the Gate hook in AppServiceProvider — so it is the role that cannot
 * be edited, cannot be deleted, and cannot be taken off the last person who
 * holds it. Without that, a single careless save could leave the business with
 * no one able to reach this screen to put it right.
 *
 * @property int $id
 * @property string $uuid
 * @property string $name
 * @property string $guard_name
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read int|null $users_count
 * @property-read Collection<int, Permission> $permissions
 */
final class Role extends SpatieRole
{
    /** @use HasFactory<RoleFactory> */
    use HasFactory;

    use HasUuids;

    public const string ADMIN = 'admin';

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    /**
     * Overridden because HasUuids assumes the uuid *is* the primary key; the
     * package's pivot tables are keyed on the integer id.
     *
     * @return array<int, string>
     */
    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    public function isAdmin(): bool
    {
        return $this->name === self::ADMIN;
    }

    /**
     * The admin permissions holding this role hands over.
     *
     * Every one of them for `admin`, whose grant comes through the Gate rather
     * than through rows — reading its rows would describe an empty role that
     * is in fact the most powerful one there is. Reads the loaded relation, so
     * a list that eager loads `permissions` asks this of every row for free.
     *
     * @return array<int, string>
     */
    public function permissionNames(): array
    {
        if ($this->isAdmin()) {
            return AdminPermission::values();
        }

        return $this->permissions
            ->map(fn (Permission $permission): string => $permission->name)
            ->values()
            ->all();
    }

    /**
     * Whether everything this role grants is something the given account can
     * already do — the test for whether they may hand it out or change it.
     */
    public function isWithinReachOf(User $user): bool
    {
        $held = array_map(
            static fn (AdminPermission $permission): string => $permission->value,
            $user->adminPermissions(),
        );

        return array_diff($this->permissionNames(), $held) === [];
    }
}
