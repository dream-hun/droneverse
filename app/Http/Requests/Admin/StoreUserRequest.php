<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Concerns\AssignsRoles;
use App\Concerns\PasswordValidationRules;
use App\Concerns\ProfileValidationRules;
use App\Enums\Plan;
use App\Models\Role;
use App\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

final class StoreUserRequest extends FormRequest
{
    use AssignsRoles;
    use PasswordValidationRules;
    use ProfileValidationRules;

    /**
     * The same name, email and password rules the sign-up form applies, so an
     * account opened by staff meets every bar one opened by its owner does.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            ...$this->profileRules(),
            'password' => $this->passwordRules(),
            'plan_override' => ['nullable', Rule::enum(Plan::class)],
            'email_verified' => ['boolean'],
            'roles' => ['sometimes', 'array', Rule::prohibitedIf(fn (): bool => ! $this->canAssignRoles())],
            'roles.*' => ['string', 'distinct', Rule::exists(Role::class, 'name')],
        ];
    }

    /**
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->has('roles') || ! in_array(Role::ADMIN, $this->roleNames(), true)) {
                    return;
                }

                if (! $this->viewer()->can('assignAdminRole', User::class)) {
                    $validator->errors()->add('roles', __('Only an admin can make somebody an admin.'));
                }
            },
            fn (Validator $validator) => $this->checkReach($validator, $this->roleNames()),
        ];
    }

    /**
     * The validated account, with roles only when the viewer may hand them out.
     *
     * @return array{name: string, email: string, password: string, plan_override: string|null, email_verified: bool, roles?: array<int, string>}
     */
    public function account(): array
    {
        $input = $this->safe();

        return [
            'name' => $input->string('name')->value(),
            'email' => $input->string('email')->value(),
            'password' => $input->string('password')->value(),
            'plan_override' => $input->filled('plan_override') ? $input->string('plan_override')->value() : null,
            'email_verified' => $input->boolean('email_verified'),
            ...($this->canAssignRoles() && $input->has('roles') ? ['roles' => $this->roleNames()] : []),
        ];
    }
}
