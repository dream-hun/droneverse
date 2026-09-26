<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Actions\BuildRevenueReport;
use App\Http\Controllers\Controller;
use App\Http\Resources\Admin\AdminOrderResource;
use App\Models\Order;
use Inertia\Inertia;
use Inertia\Response;

final class FinanceController extends Controller
{
    private const int RECENT_ORDERS = 8;

    /**
     * Revenue, the subscriber base and the latest orders.
     *
     * The report is deferred: it is the aggregates over every order and
     * subscription, and the latest orders beneath it paint without them.
     */
    public function __invoke(BuildRevenueReport $report): Response
    {
        return Inertia::render('admin/finance/index', [
            'report' => Inertia::defer(fn (): array => $report->handle()),
            'recentOrders' => AdminOrderResource::collection(
                Order::query()->with('billable')->latest('ordered_at')->latest('id')->limit(self::RECENT_ORDERS)->get(),
            ),
        ]);
    }
}
