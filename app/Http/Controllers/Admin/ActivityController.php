<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Queries\ActivityFeed;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class ActivityController extends Controller
{
    /**
     * Everything that has happened on the platform, newest first.
     *
     * A `type` the feed does not know is treated as no filter rather than a
     * 404, so a stale link still opens.
     */
    public function __invoke(Request $request, ActivityFeed $feed): Response
    {
        $kinds = ActivityFeed::KINDS;
        $type = $request->query('type');
        $type = is_string($type) && in_array($type, $kinds, true) ? $type : null;

        return Inertia::render('admin/activity', [
            'activity' => $feed->page($type === null ? $kinds : [$type], max(1, $request->integer('page', 1))),
            'kinds' => $kinds,
            'type' => $type,
        ]);
    }
}
