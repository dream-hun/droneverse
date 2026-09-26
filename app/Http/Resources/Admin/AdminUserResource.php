<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin;

use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * An account as the admin user list shows it.
 *
 * The plan is the resolved one — what the pilot can actually reach right now —
 * with the hand-set override beside it, because the two answer different
 * questions: "what do they have" and "did somebody give it to them". Resolving
 * a page of plans is a query per row unless `subscriptions` is eager loaded,
 * which is what App\Actions\ResolvePlanForUser reads from when it is.
 *
 * `can` is the viewer's standing against this account, from App\Policies\
 * UserPolicy, so the row menu offers only what the server would allow.
 *
 * @phpstan-type Row array{uuid: string, name: string, email: string, emailVerified: bool, plan: array{value: string, label: string}, planOverride: string|null, roles: array<int, string>, runs: int|null, createdAt: string, can: array{update: bool, delete: bool}}
 */
final class AdminUserResource
{
    /**
     * @param  Collection<int, User>  $users  with `roles` and `subscriptions` eager loaded
     * @return array<int, Row>
     */
    public static function collection(Collection $users, User $viewer): array
    {
        return $users->map(fn (User $user): array => self::one($user, $viewer))->values()->all();
    }

    /**
     * @return Row
     */
    public static function one(User $user, User $viewer): array
    {
        $plan = $user->plan();

        return [
            'uuid' => $user->uuid,
            'name' => $user->name,
            'email' => $user->email,
            'emailVerified' => $user->email_verified_at !== null,
            'plan' => ['value' => $plan->value, 'label' => $plan->label()],
            'planOverride' => $user->plan_override,
            'roles' => $user->roles->map(fn (Role $role): string => $role->name)->values()->all(),
            'runs' => $user->challenge_runs_count,
            'createdAt' => $user->created_at?->toIso8601String() ?? '',
            'can' => [
                'update' => $viewer->can('update', $user),
                'delete' => $viewer->can('delete', $user),
            ],
        ];
    }
}
