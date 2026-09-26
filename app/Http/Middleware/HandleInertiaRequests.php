<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Enums\AdminPermission;
use App\Enums\Feature;
use App\Enums\Plan;
use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Middleware;

final class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        /*
         * Resolved through the model rather than the action directly, so the
         * memo on the User instance is warm by the time a controller asks the
         * same question a moment later.
         */
        $plan = $request->user()?->plan() ?? Plan::Starter;

        return [
            ...parent::share($request),
            'name' => config('app.name'),
            'auth' => [
                'user' => $request->user(),
                'plan' => [
                    'value' => $plan->value,
                    'label' => $plan->label(),
                    'isPaid' => $plan->isPaid(),
                ],
                'features' => $this->grantedFeatures($plan),
                'permissions' => $this->heldPermissions($request),
            ],
            'sidebarOpen' => ! $request->hasCookie('sidebar_state') || $request->cookie('sidebar_state') === 'true',
        ];
    }

    /**
     * The admin permissions the viewer holds, as a flat list.
     *
     * The same kind of rendering hint as the features below: the sidebar
     * shows the admin sections a member of staff can open, and every admin
     * route still asks the Gate for itself. Empty for a guest and for almost
     * every pilot, which is the common case and costs one roles lookup.
     *
     * @return array<int, string>
     */
    private function heldPermissions(Request $request): array
    {
        $user = $request->user();

        if (! $user instanceof User) {
            return [];
        }

        return array_map(
            static fn (AdminPermission $permission): string => $permission->value,
            $user->adminPermissions(),
        );
    }

    /**
     * The feature flags the viewer's plan grants, as a flat list.
     *
     * Shared so React gates UI by asking what the server already decided,
     * rather than re-deriving plan rules client-side where they would drift.
     * This is a rendering hint and nothing more — every gated capability is
     * still enforced server-side by its own Gate.
     *
     * @return array<int, string>
     */
    private function grantedFeatures(Plan $plan): array
    {
        return array_values(array_map(
            static fn (Feature $feature): string => $feature->value,
            $plan->features(),
        ));
    }
}
