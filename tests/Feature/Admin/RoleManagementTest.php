<?php

declare(strict_types=1);

use App\Enums\AdminPermission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Inertia\Testing\AssertableInertia;

test('lists admin first, holding every permission', function (): void {
    $admin = User::factory()->admin()->create();
    Role::findOrCreate('auditor');

    $this->actingAs($admin)
        ->get(route('admin.roles.index'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->component('admin/roles/index')
            ->where('roles.0.name', Role::ADMIN)
            ->where('roles.0.permissions', AdminPermission::values())
            ->has('permissions', count(AdminPermission::cases())));
});

test('creates a role that grants exactly what was ticked', function (): void {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)
        ->post(route('admin.roles.store'), [
            'name' => 'auditor',
            'permissions' => [AdminPermission::AccessAdmin->value, AdminPermission::ViewFinance->value],
        ])
        ->assertRedirect();

    $auditor = User::factory()->create();
    $auditor->assignRole('auditor');

    expect($auditor->can(AdminPermission::ViewFinance->value))->toBeTrue()
        ->and($auditor->can(AdminPermission::ManageUsers->value))->toBeFalse();
});

test('rejects a permission the enum does not name', function (): void {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)
        ->post(route('admin.roles.store'), ['name' => 'rogue', 'permissions' => ['launch_missiles']])
        ->assertSessionHasErrors('permissions.0');
});

test('reserves the admin name', function (): void {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)
        ->post(route('admin.roles.store'), ['name' => 'admin', 'permissions' => []])
        ->assertSessionHasErrors(['name' => 'The admin role already exists and cannot be recreated.']);
});

test('the admin role cannot be edited or deleted', function (): void {
    $admin = User::factory()->admin()->create();
    $role = Role::findByName(Role::ADMIN);

    $this->actingAs($admin)
        ->put(route('admin.roles.update', $role), ['name' => 'renamed', 'permissions' => []])
        ->assertInertiaFlash('toast.type', 'error');

    $this->actingAs($admin)
        ->delete(route('admin.roles.destroy', $role))
        ->assertInertiaFlash('toast.type', 'error');

    expect(Role::query()->where('name', Role::ADMIN)->exists())->toBeTrue();
});

test('deleting a role takes its permissions from the people who held it', function (): void {
    $admin = User::factory()->admin()->create();
    $finance = User::factory()->withPermissions([AdminPermission::AccessAdmin, AdminPermission::ViewFinance], 'finance')->create();

    $this->actingAs($admin)
        ->delete(route('admin.roles.destroy', Role::findByName('finance')))
        ->assertRedirect();

    expect($finance->fresh()?->can(AdminPermission::ViewFinance->value))->toBeFalse();
});

test('admin:grant makes an existing account an admin', function (): void {
    $user = User::factory()->create(['email' => 'first@example.com']);

    expect(Artisan::call('admin:grant', ['email' => 'first@example.com']))->toBe(0);

    expect($user->fresh()?->can(AdminPermission::ManageRoles->value))->toBeTrue();
});

test('admin:grant refuses an address nobody has registered', function (): void {
    expect(Artisan::call('admin:grant', ['email' => 'nobody@example.com']))->toBe(1);

    expect(Role::query()->where('name', Role::ADMIN)->exists())->toBeFalse();
});
