<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\Role;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Rename a staff role, or change what it may do.
 *
 * Every account holding the role gains or loses the difference at once — the
 * package caches permissions and drops that cache on every role save, so
 * nobody keeps a revoked permission until their session ends.
 *
 * The `admin` role is refused. It holds everything by definition rather than
 * by rows, so an edit would either change nothing or, renamed, quietly strip
 * every admin of the blanket grant the name carries.
 */
final readonly class UpdateRole
{
    public function __construct(private SyncAdminPermissions $sync) {}

    /**
     * False when the role is `admin`, and nothing changed.
     *
     * @param  array<int, string>  $permissions
     *
     * @throws Throwable
     */
    public function handle(Role $role, string $name, array $permissions): bool
    {
        if ($role->isAdmin()) {
            return false;
        }

        $this->sync->handle();

        DB::transaction(function () use ($role, $name, $permissions): void {
            $role->update(['name' => $name]);
            $role->syncPermissions($permissions);
        });

        return true;
    }
}
