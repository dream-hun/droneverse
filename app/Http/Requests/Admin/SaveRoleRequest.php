<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Enums\AdminPermission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Creating a role and editing one share every rule but uniqueness.
 *
 * Names are lower-case slugs because they are identifiers as much as labels —
 * they are what a role is found by, and what a support ticket quotes. `admin`
 * is reserved: that role is seeded, and a second one by the same name could
 * not exist anyway, but saying so is kinder than a uniqueness error.
 *
 * A permission can only be ticked by somebody who holds it — the other half
 * of the rule App\Policies\RolePolicy states — so managing roles is a way to
 * share what you have, never a way to get more.
 */
final class SaveRoleRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $role = $this->route('role');

        return [
            'name' => [
                'required',
                'string',
                'max:50',
                'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/',
                Rule::notIn([Role::ADMIN]),
                Rule::unique(Role::class, 'name')->ignore($role instanceof Role ? $role->id : null),
            ],
            'permissions' => ['present', 'array'],
            'permissions.*' => ['string', 'distinct', Rule::enum(AdminPermission::class)],
        ];
    }

    /**
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->has('permissions') || $validator->errors()->has('permissions.*')) {
                    return;
                }

                $viewer = $this->user();

                abort_unless($viewer instanceof User, 403);

                $unheld = array_filter(
                    $this->permissions(),
                    static fn (string $permission): bool => ! $viewer->can($permission),
                );

                if ($unheld !== []) {
                    $validator->errors()->add('permissions', __('You can only grant permissions you hold yourself.'));
                }
            },
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.regex' => __('Use lower-case letters, numbers and single hyphens, like content-manager.'),
            'name.not_in' => __('The admin role already exists and cannot be recreated.'),
        ];
    }

    /**
     * @return array<int, string>
     */
    public function permissions(): array
    {
        $permissions = $this->input('permissions', []);

        return is_array($permissions) ? array_values(array_filter($permissions, is_string(...))) : [];
    }
}
