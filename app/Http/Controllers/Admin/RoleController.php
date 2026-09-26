<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Actions\CreateRole;
use App\Actions\DeleteRole;
use App\Actions\UpdateRole;
use App\Enums\AdminPermission;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\SaveRoleRequest;
use App\Http\Resources\Admin\AdminRoleResource;
use App\Models\Role;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

final class RoleController extends Controller
{
    /**
     * Every staff role, `admin` first, and the permissions a role can hold.
     */
    public function index(#[CurrentUser] User $viewer): Response
    {
        $roles = Role::query()
            ->with('permissions')
            ->withCount('users')
            ->orderByRaw('name = ? desc', [Role::ADMIN])
            ->orderBy('name')
            ->get();

        return Inertia::render('admin/roles/index', [
            'roles' => AdminRoleResource::collection($roles, $viewer),
            /*
             * The permissions this viewer may tick on a role — the ones they
             * hold. The form shows the rest disabled rather than hiding them,
             * so a role that already carries one still reads truthfully.
             */
            'grantable' => array_map(
                static fn (AdminPermission $permission): string => $permission->value,
                $viewer->adminPermissions(),
            ),
            'permissions' => array_map(
                static fn (AdminPermission $permission): array => [
                    'value' => $permission->value,
                    'label' => $permission->label(),
                    'description' => $permission->description(),
                ],
                AdminPermission::cases(),
            ),
        ]);
    }

    /**
     * @throws Throwable
     */
    public function store(SaveRoleRequest $request, CreateRole $create): RedirectResponse
    {
        $role = $create->handle($request->string('name')->value(), $request->permissions());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Role :name created.', ['name' => $role->name])]);

        return back();
    }

    /**
     * @throws Throwable
     */
    public function update(SaveRoleRequest $request, Role $role, UpdateRole $update): RedirectResponse
    {
        Gate::authorize('update', $role);

        $updated = $update->handle($role, $request->string('name')->value(), $request->permissions());

        Inertia::flash('toast', $updated
            ? ['type' => 'success', 'message' => __('Role updated.')]
            : ['type' => 'error', 'message' => __('The admin role holds every permission and cannot be edited.')]);

        return back();
    }

    public function destroy(Role $role, DeleteRole $delete): RedirectResponse
    {
        Gate::authorize('delete', $role);

        $deleted = $delete->handle($role);

        Inertia::flash('toast', $deleted
            ? ['type' => 'success', 'message' => __('Role deleted.')]
            : ['type' => 'error', 'message' => __('The admin role cannot be deleted.')]);

        return back();
    }
}
