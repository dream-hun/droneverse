<?php

declare(strict_types=1);

namespace App\Concerns;

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * The role field shared by the admin forms that open and edit accounts.
 *
 * Both forms take the same list of role names and hold it to the same rule:
 * a role can only be handed out by somebody who already holds everything it
 * grants. The reasoning is App\Policies\UserPolicy's; this is where the two
 * requests ask it.
 *
 * @mixin FormRequest
 */
trait AssignsRoles
{
    /**
     * @return array<int, string>
     */
    protected function roleNames(): array
    {
        $roles = $this->input('roles', []);

        return is_array($roles) ? array_values(array_filter($roles, is_string(...))) : [];
    }

    protected function canAssignRoles(): bool
    {
        return $this->viewer()->can('assignRoles', User::class);
    }

    protected function viewer(): User
    {
        $user = $this->user();

        abort_unless($user instanceof User, 403);

        return $user;
    }

    /**
     * Refuse any role that grants something the viewer cannot do themselves.
     *
     * `admin` is left out, because each request checks it separately and says
     * why in its own words.
     *
     * @param  array<int, string>  $names
     */
    protected function checkReach(Validator $validator, array $names): void
    {
        if ($validator->errors()->has('roles') || $names === [] || ! $this->canAssignRoles()) {
            return;
        }

        $outOfReach = Role::query()
            ->with('permissions')
            ->whereIn('name', array_diff($names, [Role::ADMIN]))
            ->get()
            ->reject(fn (Role $role): bool => $this->viewer()->can('assignRole', [User::class, $role]))
            ->map(fn (Role $role): string => $role->name);

        if ($outOfReach->isNotEmpty()) {
            $validator->errors()->add('roles', __('You can only assign roles whose permissions you hold yourself: :roles.', [
                'roles' => $outOfReach->join(', '),
            ]));
        }
    }
}
