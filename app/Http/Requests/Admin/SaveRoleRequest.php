<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Enums\AdminPermission;
use App\Models\Role;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Creating a role and editing one share every rule but uniqueness.
 *
 * Names are lower-case slugs because they are identifiers as much as labels —
 * they are what a role is found by, and what a support ticket quotes. `admin`
 * is reserved: that role is seeded, and a second one by the same name could
 * not exist anyway, but saying so is kinder than a uniqueness error.
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
        $permissions = $this->safe()->array('permissions');

        return array_values(array_filter($permissions, is_string(...)));
    }
}
