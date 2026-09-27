<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Actions\BuildAdminAccount;
use App\Actions\CreateUser;
use App\Actions\DeleteUser;
use App\Actions\UpdateUser;
use App\Enums\Plan;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreUserRequest;
use App\Http\Requests\Admin\UpdateUserRequest;
use App\Http\Resources\Admin\AdminUserResource;
use App\Http\Resources\Admin\PaginationResource;
use App\Models\Role;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

final class UserController extends Controller
{
    private const int PER_PAGE = 25;

    /**
     * Every account, newest first, searchable by name or address.
     *
     * `role` narrows to holders of one role, or to `staff` — anyone holding
     * any role — which is the question asked when auditing who can reach
     * this area at all.
     */
    public function index(Request $request, #[CurrentUser] User $viewer): Response
    {
        $search = mb_trim((string) $request->string('q'));
        $role = mb_trim((string) $request->string('role'));

        $users = User::query()
            ->with(['roles', 'subscriptions'])
            ->withCount('challengeRuns')
            ->when($search !== '', fn (Builder $query): Builder => $query->where(
                fn (Builder $match): Builder => $match
                    ->where('name', 'like', '%'.$search.'%')
                    ->orWhere('email', 'like', '%'.$search.'%'),
            ))
            ->when($role === 'staff', fn (Builder $query): Builder => $query->whereHas('roles'))
            ->when($role !== '' && $role !== 'staff', fn (Builder $query): Builder => $query->role($role))
            ->latest('id')
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        return Inertia::render('admin/users/index', [
            'users' => AdminUserResource::collection($users->getCollection(), $viewer),
            'pagination' => PaginationResource::of($users),
            'filters' => ['q' => $search, 'role' => $role],
            ...$this->formOptions($viewer),
        ]);
    }

    /**
     * @throws Throwable
     */
    public function store(StoreUserRequest $request, CreateUser $create): RedirectResponse
    {
        $user = $create->handle($request->account());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Account created for :email.', ['email' => $user->email])]);

        return back();
    }

    /**
     * One account: who they are, what they pay for, and what they have flown.
     */
    public function show(User $user, #[CurrentUser] User $viewer, BuildAdminAccount $build): Response
    {
        return Inertia::render('admin/users/show', [
            ...$build->handle($user, $viewer),
            ...$this->formOptions($viewer),
        ]);
    }

    /**
     * @throws Throwable
     */
    public function update(UpdateUserRequest $request, User $user, UpdateUser $update): RedirectResponse
    {
        $update->handle($user, $request->changes());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Account updated.')]);

        return back();
    }

    /**
     * @throws Throwable
     */
    public function destroy(User $user, DeleteUser $delete): RedirectResponse
    {
        Gate::authorize('delete', $user);

        if (! $delete->handle($user)) {
            Inertia::flash('toast', [
                'type' => 'error',
                'message' => __('This account still has a subscription Creem is billing. Cancel it from the account page first, then delete the account.'),
            ]);

            return back();
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Account deleted.')]);

        return to_route('admin.users.index');
    }

    /**
     * What the create and edit forms offer, and what the viewer may change.
     *
     * `assignable` is App\Policies\UserPolicy::assignRole()'s answer per role,
     * so the form disables what the server would refuse.
     *
     * @return array{roles: array<int, array{name: string, isAdmin: bool, assignable: bool}>, plans: array<int, array{value: string, label: string}>, can: array{assignRoles: bool, assignAdminRole: bool}}
     */
    private function formOptions(User $viewer): array
    {
        return [
            'roles' => Role::query()
                ->with('permissions')
                ->orderByRaw('name = ? desc', [Role::ADMIN])
                ->orderBy('name')
                ->get()
                ->map(fn (Role $role): array => [
                    'name' => $role->name,
                    'isAdmin' => $role->isAdmin(),
                    'assignable' => $viewer->can('assignRole', [User::class, $role]),
                ])
                ->all(),
            'plans' => array_map(
                static fn (Plan $plan): array => ['value' => $plan->value, 'label' => $plan->label()],
                Plan::cases(),
            ),
            'can' => [
                'assignRoles' => $viewer->can('assignRoles', User::class),
                'assignAdminRole' => $viewer->can('assignAdminRole', User::class),
            ],
        ];
    }
}
