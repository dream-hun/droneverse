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

test('the admin role cannot be edited or deleted, even by an admin', function (): void {
    $admin = User::factory()->admin()->create();
    $role = Role::findByName(Role::ADMIN);

    $this->actingAs($admin)
        ->put(route('admin.roles.update', $role), ['name' => 'renamed', 'permissions' => []])
        ->assertForbidden();

    $this->actingAs($admin)
        ->delete(route('admin.roles.destroy', $role))
        ->assertForbidden();

    expect(Role::query()->where('name', Role::ADMIN)->exists())->toBeTrue();
});

describe('a role manager who is not an admin', function (): void {
    /**
     * Can open the users and roles screens, and do nothing else.
     */
    function roleManager(): User
    {
        return User::factory()->withPermissions([
            AdminPermission::AccessAdmin,
            AdminPermission::ManageUsers,
            AdminPermission::ManageRoles,
        ], 'people-lead')->create();
    }

    test('may create a role from permissions they hold', function (): void {
        $this->actingAs(roleManager())
            ->post(route('admin.roles.store'), [
                'name' => 'support',
                'permissions' => [AdminPermission::AccessAdmin->value, AdminPermission::ManageUsers->value],
            ])
            ->assertSessionHasNoErrors();

        expect(Role::query()->where('name', 'support')->sole()->permissionNames())->toHaveCount(2);
    });

    test('cannot grant a permission they do not hold', function (): void {
        $this->actingAs(roleManager())
            ->post(route('admin.roles.store'), [
                'name' => 'treasurer',
                'permissions' => [AdminPermission::AccessAdmin->value, AdminPermission::ViewFinance->value],
            ])
            ->assertSessionHasErrors(['permissions' => 'You can only grant permissions you hold yourself.']);

        expect(Role::query()->where('name', 'treasurer')->exists())->toBeFalse();
    });

    test('cannot add a permission they lack to the role they hold', function (): void {
        $manager = roleManager();

        $this->actingAs($manager)
            ->put(route('admin.roles.update', Role::findByName('people-lead')), [
                'name' => 'people-lead',
                'permissions' => [
                    AdminPermission::AccessAdmin->value,
                    AdminPermission::ManageUsers->value,
                    AdminPermission::ManageRoles->value,
                    AdminPermission::ViewSystem->value,
                ],
            ])
            ->assertSessionHasErrors('permissions');

        expect($manager->fresh()?->can(AdminPermission::ViewSystem->value))->toBeFalse();
    });

    test('cannot edit or delete a role that grants more than they hold', function (): void {
        $manager = roleManager();
        User::factory()->withPermissions([AdminPermission::AccessAdmin, AdminPermission::ViewFinance], 'finance')->create();
        $finance = Role::findByName('finance');

        $this->actingAs($manager)
            ->put(route('admin.roles.update', $finance), ['name' => 'finance', 'permissions' => []])
            ->assertForbidden();

        $this->actingAs($manager)
            ->delete(route('admin.roles.destroy', $finance))
            ->assertForbidden();
    });

    test('cannot hand a pilot a role that grants more than they hold', function (): void {
        $manager = roleManager();
        User::factory()->withPermissions([AdminPermission::AccessAdmin, AdminPermission::ViewFinance], 'finance')->create();
        $pilot = User::factory()->create();

        $this->actingAs($manager)
            ->put(route('admin.users.update', $pilot), [
                'name' => $pilot->name,
                'email' => $pilot->email,
                'roles' => ['finance'],
            ])
            ->assertSessionHasErrors(['roles' => 'You can only assign roles whose permissions you hold yourself: finance.']);

        expect($pilot->fresh()?->hasRole('finance'))->toBeFalse();
    });

    test('may hand a pilot a role within their reach', function (): void {
        $manager = roleManager();
        User::factory()->withPermissions([AdminPermission::AccessAdmin, AdminPermission::ManageUsers], 'support')->create();
        $pilot = User::factory()->create();

        $this->actingAs($manager)
            ->put(route('admin.users.update', $pilot), [
                'name' => $pilot->name,
                'email' => $pilot->email,
                'roles' => ['support'],
            ])
            ->assertSessionHasNoErrors();

        expect($pilot->fresh()?->hasRole('support'))->toBeTrue();
    });

    test('sees which roles and permissions are out of reach', function (): void {
        $manager = roleManager();
        User::factory()->withPermissions([AdminPermission::AccessAdmin, AdminPermission::ViewFinance], 'finance')->create();

        $this->actingAs($manager)
            ->get(route('admin.roles.index'))
            ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
                ->where('grantable', [
                    AdminPermission::AccessAdmin->value,
                    AdminPermission::ManageUsers->value,
                    AdminPermission::ManageRoles->value,
                ])
                ->where('roles.0.name', 'finance')
                ->where('roles.0.can.update', false)
                ->where('roles.1.name', 'people-lead')
                ->where('roles.1.can.update', true));
    });
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
