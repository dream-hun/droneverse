<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Actions\BuildAdminOverview;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Queries\ActivityFeed;
use Illuminate\Container\Attributes\CurrentUser;
use Inertia\Inertia;
use Inertia\Response;

final class DashboardController extends Controller
{
    /** Recent events shown under the numbers; the activity page has the rest. */
    private const int RECENT_ACTIVITY = 12;

    /**
     * The front page of the admin area.
     *
     * The numbers are deferred so the page paints before the aggregates run —
     * the recent activity is cheap, bounded reads by primary key, and it is
     * what someone opening this page usually came to look at.
     */
    public function __invoke(#[CurrentUser] User $user, BuildAdminOverview $overview, ActivityFeed $feed): Response
    {
        return Inertia::render('admin/dashboard', [
            'overview' => Inertia::defer(fn (): array => $overview->handle()),
            'activity' => $feed->page($feed->kindsVisibleTo($user), 1, self::RECENT_ACTIVITY)['items'],
        ]);
    }
}
