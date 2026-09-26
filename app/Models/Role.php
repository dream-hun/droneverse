<?php

declare(strict_types=1);

namespace App\Models;

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
}
