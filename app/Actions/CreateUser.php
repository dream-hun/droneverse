<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Open an account on somebody's behalf from the admin area.
 *
 * Registration is the ordinary way in and this is the exception to it — a
 * pilot a school is enrolling, a comped reviewer, a colleague. So it can do
 * the two things the sign-up form cannot: set a plan by hand, and vouch for an
 * address without a verification mail. Neither is a default. An account made
 * here is unverified unless whoever made it says otherwise, because the person
 * typing the address is not the person who owns it.
 */
final readonly class CreateUser
{
    /**
     * @param  array{name: string, email: string, password: string, plan_override: string|null, email_verified: bool, roles?: array<int, string>}  $attributes
     *
     * @throws Throwable
     */
    public function handle(array $attributes): User
    {
        return DB::transaction(function () use ($attributes): User {
            $user = User::query()->create([
                'name' => $attributes['name'],
                'email' => $attributes['email'],
                'password' => $attributes['password'],
                'plan_override' => $attributes['plan_override'],
                'email_verified_at' => $attributes['email_verified'] ? now() : null,
            ]);

            if (isset($attributes['roles'])) {
                $user->syncRoles($attributes['roles']);
            }

            return $user;
        });
    }
}
