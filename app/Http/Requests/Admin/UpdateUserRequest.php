<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Concerns\AssignsRoles;
use App\Concerns\ProfileValidationRules;
use App\Enums\Plan;
use App\Models\Role;
use App\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

final class UpdateUserRequest extends FormRequest
{
    use AssignsRoles;
    use ProfileValidationRules;

    /**
     * Whether the viewer may edit this account at all — see App\Policies\
     * UserPolicy for why staff accounts are admins' alone.
     */
    public function authorize(): bool
    {
        return $this->viewer()->can('update', $this->account());
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            ...$this->profileRules($this->account()->id),
            'plan_override' => ['nullable', Rule::enum(Plan::class)],
            'roles' => ['sometimes', 'array', Rule::prohibitedIf(fn (): bool => ! $this->canAssignRoles())],
            'roles.*' => ['string', 'distinct', Rule::exists(Role::class, 'name')],
        ];
    }

    /**
     * Two ways a role change could hand out more than the viewer holds, or
     * leave the business with less than it needs.
     *
     * Granting or revoking `admin` takes an admin. And an admin cannot take it
     * off their own account: only admins act on admins, so an admin who could
     * demote themselves could also be the last one to do it.
     *
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->has('roles') || ! $this->has('roles') || ! $this->canAssignRoles()) {
                    return;
                }

                $account = $this->account();
                $hadAdmin = $account->hasRole(Role::ADMIN);
                $keepsAdmin = in_array(Role::ADMIN, $this->roleNames(), true);

                if ($hadAdmin === $keepsAdmin) {
                    return;
                }

                if (! $this->viewer()->can('assignAdminRole', User::class)) {
                    $validator->errors()->add('roles', __('Only an admin can grant or remove the admin role.'));

                    return;
                }

                if ($hadAdmin && $account->is($this->viewer())) {
                    $validator->errors()->add('roles', __('You cannot remove the admin role from your own account.'));
                }
            },
            function (Validator $validator): void {
                if (! $this->has('roles')) {
                    return;
                }

                // Only roles being added are checked. A role the account
                // already holds is not being handed out by this save.
                $this->checkReach($validator, array_values(array_diff(
                    $this->roleNames(),
                    $this->account()->roles->map(fn (Role $role): string => $role->name)->all(),
                )));
            },
        ];
    }

    /**
     * @return array{name: string, email: string, plan_override: string|null, roles?: array<int, string>}
     */
    public function changes(): array
    {
        $input = $this->safe();

        return [
            'name' => $input->string('name')->value(),
            'email' => $input->string('email')->value(),
            'plan_override' => $input->filled('plan_override') ? $input->string('plan_override')->value() : null,
            ...($this->canAssignRoles() && $input->has('roles') ? ['roles' => $this->roleNames()] : []),
        ];
    }

    private function account(): User
    {
        $account = $this->route('user');

        abort_unless($account instanceof User, 404);

        return $account;
    }
}
