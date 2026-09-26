<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Actions\CreateUser;
use App\Actions\DeleteUser;
use App\Actions\UpdateUser;
use App\Enums\ChallengeStatus;
use App\Enums\Plan;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreUserRequest;
use App\Http\Requests\Admin\UpdateUserRequest;
use App\Http\Resources\Admin\AdminOrderResource;
use App\Http\Resources\Admin\AdminSubscriptionResource;
use App\Http\Resources\Admin\AdminUserResource;
use App\Http\Resources\Admin\PaginationResource;
use App\Models\ChallengeRun;
use App\Models\Order;
use App\Models\Role;
use App\Models\Subscription;
use App\Models\User;
use App\Queries\DefaultSubscription;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

final class UserController extends Controller
{
    private const int PER_PAGE = 25;

    /** Recent runs, orders and subscriptions on an account's page. */
    private const int RECENT_LIMIT = 10;

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
    public function show(User $user, #[CurrentUser] User $viewer, DefaultSubscription $defaultSubscription): Response
    {
        $user->load(['roles', 'subscriptions'])->loadCount('challengeRuns');

        $runs = ChallengeRun::query()
            ->where('user_id', $user->id)
            ->with(['challenge' => function (Relation $challenge): void {
                $challenge->select(['id', 'course_id', 'title', 'slug']);
                $challenge->with(['course' => function (Relation $course): void {
                    $course->select(['id', 'title', 'slug']);
                }]);
            }])
            ->latest('id')
            ->limit(self::RECENT_LIMIT)
            ->get();

        return Inertia::render('admin/users/show', [
            'account' => AdminUserResource::one($user, $viewer),
            'stats' => [
                'missionsCompleted' => $user->challengeProgress()->where('status', ChallengeStatus::Completed)->count(),
                'quizzesPassed' => $user->quizProgress()->whereNotNull('passed_at')->count(),
                'photos' => $user->dronePhotos()->count(),
                'lastRunAt' => $runs->first()?->created_at?->toIso8601String(),
            ],
            'recentRuns' => $runs->map(fn (ChallengeRun $run): array => [
                'uuid' => $run->uuid,
                'challengeTitle' => $run->challenge->title ?? __('A retired mission'),
                'courseTitle' => $run->challenge->course->title ?? '',
                'score' => $run->score,
                'stars' => $run->stars,
                'completed' => $run->completed,
                'collisions' => $run->collisions,
                'elapsedSeconds' => $run->elapsed_seconds,
                'flownAt' => $run->created_at?->toIso8601String() ?? '',
            ])->all(),
            'subscriptions' => $viewer->can('view_finance')
                ? AdminSubscriptionResource::collection(
                    Subscription::query()->whereMorphedTo('billable', $user)->with('billable')->latest('id')->limit(self::RECENT_LIMIT)->get(),
                )
                : null,
            /*
             * Offered only where cancelling would do something: a subscription
             * still billing, on an account this viewer may edit, to somebody
             * trusted with the money.
             */
            'canCancelSubscription' => $viewer->can('view_finance')
                && $viewer->can('update', $user)
                && $defaultSubscription->isSwitchable($defaultSubscription->for($user)),
            'orders' => $viewer->can('view_finance')
                ? AdminOrderResource::collection(
                    Order::query()->whereMorphedTo('billable', $user)->with('billable')->latest('ordered_at')->latest('id')->limit(self::RECENT_LIMIT)->get(),
                )
                : null,
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
