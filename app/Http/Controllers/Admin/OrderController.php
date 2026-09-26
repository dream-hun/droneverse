<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\Admin\AdminOrderResource;
use App\Http\Resources\Admin\PaginationResource;
use App\Models\Order;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class OrderController extends Controller
{
    private const int PER_PAGE = 25;

    /**
     * Every order Creem has reported, newest first.
     *
     * Searchable by the pilot's name or address, or by Creem's own order id —
     * which is what a customer quotes when they write in about a charge.
     */
    public function __invoke(Request $request): Response
    {
        $search = mb_trim((string) $request->string('q'));
        $refunded = $request->query('refunded');
        $refunded = in_array($refunded, ['yes', 'no'], true) ? $refunded : null;

        $orders = Order::query()
            ->with('billable')
            ->when($search !== '', fn (Builder $query): Builder => $query->where(
                fn (Builder $match): Builder => $match
                    ->where('creem_id', $search)
                    ->orWhereHasMorph('billable', [User::class], fn (Builder $user): Builder => $user
                        ->where('name', 'like', '%'.$search.'%')
                        ->orWhere('email', 'like', '%'.$search.'%')),
            ))
            ->when($refunded !== null, fn (Builder $query): Builder => $query->where('refunded', $refunded === 'yes'))
            ->latest('ordered_at')
            ->latest('id')
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        return Inertia::render('admin/finance/orders', [
            'orders' => AdminOrderResource::collection($orders->getCollection()),
            'pagination' => PaginationResource::of($orders),
            'filters' => ['q' => $search, 'refunded' => $refunded],
        ]);
    }
}
