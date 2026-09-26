<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Enums\SubscriptionStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\Admin\AdminSubscriptionResource;
use App\Http\Resources\Admin\PaginationResource;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class SubscriptionController extends Controller
{
    private const int PER_PAGE = 25;

    /**
     * Every subscription, newest first, filterable by Creem's status.
     *
     * The status filter offers the statuses this application knows. A value
     * Creem adds later still lists under "all", shown as it arrived.
     */
    public function __invoke(Request $request): Response
    {
        $search = mb_trim((string) $request->string('q'));
        $status = SubscriptionStatus::tryFrom((string) $request->string('status'));

        $subscriptions = Subscription::query()
            ->with('billable')
            ->when($search !== '', fn (Builder $query): Builder => $query->where(
                fn (Builder $match): Builder => $match
                    ->where('creem_id', $search)
                    ->orWhereHasMorph('billable', [User::class], fn (Builder $user): Builder => $user
                        ->where('name', 'like', '%'.$search.'%')
                        ->orWhere('email', 'like', '%'.$search.'%')),
            ))
            ->when($status instanceof SubscriptionStatus, fn (Builder $query): Builder => $query->where('status', $status?->value))
            ->latest('id')
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        return Inertia::render('admin/finance/subscriptions', [
            'subscriptions' => AdminSubscriptionResource::collection($subscriptions->getCollection()),
            'pagination' => PaginationResource::of($subscriptions),
            'filters' => ['q' => $search, 'status' => $status?->value],
            'statuses' => array_map(
                static fn (SubscriptionStatus $case): array => ['value' => $case->value, 'entitles' => $case->entitles()],
                SubscriptionStatus::cases(),
            ),
        ]);
    }
}
