<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Queries\ActivityFeed;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class ActivityController extends Controller
{
    /**
     * Everything that has happened on the platform, newest first.
     *
     * A `type` the viewer may not read — money, without `view_finance` — is
     * treated as no filter rather than a 403, so a link shared between two
     * members of staff opens for both and shows each what they are allowed.
     */
    public function __invoke(Request $request, #[CurrentUser] User $user, ActivityFeed $feed): Response
    {
        $kinds = $feed->kindsVisibleTo($user);
        $type = $request->query('type');
        $type = is_string($type) && in_array($type, $kinds, true) ? $type : null;

        return Inertia::render('admin/activity', [
            'activity' => $feed->page($type === null ? $kinds : [$type], max(1, $request->integer('page', 1))),
            'kinds' => $kinds,
            'type' => $type,
        ]);
    }
}
