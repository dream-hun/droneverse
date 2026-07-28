<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Enums\Feature;
use App\Enums\Plan;
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
            ],
            'sidebarOpen' => ! $request->hasCookie('sidebar_state') || $request->cookie('sidebar_state') === 'true',
        ];
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
