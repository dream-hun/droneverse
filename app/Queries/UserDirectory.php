<?php

declare(strict_types=1);

namespace App\Queries;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * Every account, as the admin user list reads it.
 */
final readonly class UserDirectory
{
    /**
     * One page of accounts, newest first, matching the admin filters.
     *
     * `search` matches a substring of the name or address. `role` narrows to
     * holders of one role, or to `staff` — anyone holding any role — which is
     * the question asked when auditing who can reach the admin area at all.
     * An empty string leaves either filter off.
     *
     * @return LengthAwarePaginator<int, User>
     */
    public function page(string $search, string $role, int $perPage): LengthAwarePaginator
    {
        return User::query()
            ->with('roles')
            ->withCount('challengeRuns')
            ->when($search !== '', fn (Builder $query): Builder => $query->where(
                fn (Builder $match): Builder => $match
                    ->where('name', 'like', '%'.$search.'%')
                    ->orWhere('email', 'like', '%'.$search.'%'),
            ))
            ->when($role === 'staff', fn (Builder $query): Builder => $query->whereHas('roles'))
            ->when($role !== '' && $role !== 'staff', fn (Builder $query): Builder => $query->role($role))
            ->latest('id')
            ->paginate($perPage)
            ->withQueryString();
    }
}
