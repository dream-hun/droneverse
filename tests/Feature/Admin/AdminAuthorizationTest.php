<?php

declare(strict_types=1);

use App\Enums\AdminPermission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Spatie\Permission\Models\Permission;

test('guests are sent to sign in', function (): void {
    $this->get(route('admin.users.index'))->assertRedirect(route('login'));
});

test('an ordinary pilot cannot reach the admin area', function (): void {
    $this->actingAs(User::factory()->create())
        ->put(route('admin.users.update', User::factory()->create()), [])
        ->assertForbidden();
});

test('a section permission without access_admin reaches nothing', function (): void {
    $staff = User::factory()->withPermissions([AdminPermission::ManageUsers])->create();

    $this->actingAs($staff)
        ->delete(route('admin.users.destroy', User::factory()->create()))
        ->assertForbidden();
});

test('support can edit a pilot but not an admin', function (): void {
    $support = User::factory()
        ->withPermissions([AdminPermission::AccessAdmin, AdminPermission::ManageUsers], 'support')
        ->create();
    $pilot = User::factory()->create();
    $admin = User::factory()->admin()->create();

    $this->actingAs($support)
        ->put(route('admin.users.update', $pilot), ['name' => 'Renamed', 'email' => $pilot->email])
        ->assertRedirect();

    expect($pilot->fresh()?->name)->toBe('Renamed');

    // The takeover route: pointing an admin's address at one support owns.
    $this->actingAs($support)
        ->put(route('admin.users.update', $admin), ['name' => $admin->name, 'email' => 'mine@example.com'])
        ->assertForbidden();

    expect($admin->fresh()?->email)->not->toBe('mine@example.com');
});

test('a role manager cannot grant the admin role', function (): void {
    $manager = User::factory()->withPermissions([
        AdminPermission::AccessAdmin,
        AdminPermission::ManageUsers,
        AdminPermission::ManageRoles,
    ])->create();
    $pilot = User::factory()->create();
    Role::findOrCreate(Role::ADMIN);

    $this->actingAs($manager)
        ->put(route('admin.users.update', $pilot), [
            'name' => $pilot->name,
            'email' => $pilot->email,
            'roles' => [Role::ADMIN],
        ])
        ->assertSessionHasErrors(['roles' => 'Only an admin can grant or remove the admin role.']);

    expect($pilot->fresh()?->hasRole(Role::ADMIN))->toBeFalse();
});

test('an admin cannot remove their own admin role', function (): void {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)
        ->put(route('admin.users.update', $admin), ['name' => $admin->name, 'email' => $admin->email, 'roles' => []])
        ->assertSessionHasErrors(['roles' => 'You cannot remove the admin role from your own account.']);

    expect($admin->fresh()?->hasRole(Role::ADMIN))->toBeTrue();
});

test('an admin cannot delete their own account from the admin area', function (): void {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)->delete(route('admin.users.destroy', $admin))->assertForbidden();

    $this->assertModelExists($admin);
});

test('the sidebar is shared the admin permissions a member of staff holds', function (): void {
    $support = User::factory()
        ->withPermissions([AdminPermission::AccessAdmin, AdminPermission::ManageUsers], 'support')
        ->create();

    $this->actingAs($support)
        ->get(route('dashboard'))
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->where('auth.permissions', [AdminPermission::AccessAdmin->value, AdminPermission::ManageUsers->value]));
});

test('the sidebar is shared every admin permission for the admin role', function (): void {
    $this->actingAs(User::factory()->admin()->create())
        ->get(route('dashboard'))
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->where('auth.permissions', AdminPermission::values()));
});

test('a permission granted to the account directly still reaches the sidebar', function (): void {
    /*
     * The application only grants through roles today, but the check that
     * skips the Gate for pilots reads both relations, so a direct grant is
     * never mistaken for holding nothing.
     */
    $staff = User::factory()->create();
    $staff->givePermissionTo(Permission::findOrCreate(AdminPermission::AccessAdmin->value));

    $this->actingAs($staff)
        ->get(route('dashboard'))
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->where('auth.permissions', [AdminPermission::AccessAdmin->value]));
});

test('a pilot is shared no admin permissions for a single query', function (): void {
    $pilot = User::factory()->create();

    $this->actingAs($pilot)->get(route('pricing'))->assertOk();

    // A fresh instance, so no relation loaded by the request above is reused.
    $this->actingAs(User::query()->findOrFail($pilot->id));

    DB::enableQueryLog();

    $this->get(route('pricing'))
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->where('auth.permissions', []));

    $queries = collect(DB::getQueryLog())->pluck('query');

    expect($queries)->toHaveCount(1)
        ->and($queries->first())->toContain('model_has_roles')
        ->and($queries->first())->toContain('model_has_permissions');
});
