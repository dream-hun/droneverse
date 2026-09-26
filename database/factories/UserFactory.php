<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\AdminPermission;
use App\Enums\Plan;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;

/**
 * @extends Factory<User>
 */
final class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    private static string $password;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => self::$password ??= Hash::make('password'),
            'plan_override' => null,
            'remember_token' => Str::random(10),
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
        ];
    }

    /**
     * Grant a plan directly, the way a comped or academic account is set up.
     */
    public function onPlan(Plan $plan): static
    {
        return $this->state(fn (array $attributes): array => [
            'plan_override' => $plan->value,
        ]);
    }

    /**
     * Hold the `admin` role, which carries every admin permission.
     */
    public function admin(): static
    {
        return $this->afterCreating(function (User $user): void {
            $user->assignRole(Role::findOrCreate(Role::ADMIN));
        });
    }

    /**
     * Hold a staff role granting exactly the given admin permissions.
     *
     * The role is found or created by name, so two accounts given the same
     * name share one role rather than colliding on it.
     *
     * @param  array<int, AdminPermission>  $permissions
     */
    public function withPermissions(array $permissions, string $role = 'staff'): static
    {
        return $this->afterCreating(function (User $user) use ($permissions, $role): void {
            $staff = Role::findOrCreate($role);

            foreach ($permissions as $permission) {
                Permission::findOrCreate($permission->value);
            }

            $staff->syncPermissions(array_map(
                static fn (AdminPermission $permission): string => $permission->value,
                $permissions,
            ));

            $user->assignRole($staff);
        });
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes): array => [
            'email_verified_at' => null,
        ]);
    }

    /**
     * Indicate that the model has two-factor authentication configured.
     */
    public function withTwoFactor(): static
    {
        return $this->state(fn (array $attributes): array => [
            'two_factor_secret' => encrypt('secret'),
            'two_factor_recovery_codes' => encrypt(json_encode(['recovery-code-1'])),
            'two_factor_confirmed_at' => now(),
        ]);
    }
}
