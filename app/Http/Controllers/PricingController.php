<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\BuildPricingCatalog;
use App\Enums\Plan;
use App\Http\Integrations\Kelviq;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class PricingController extends Controller
{
    /**
     * The plans, their prices and what each one unlocks.
     *
     * Public, and rendered from local configuration alone: it reaches Kelviq
     * only through the viewer's own entitlements, so it renders the same in an
     * environment with no Kelviq account as in one with it.
     */
    public function __invoke(Request $request, BuildPricingCatalog $catalog, Kelviq $kelviq): Response
    {
        $user = $request->user();

        return Inertia::render('pricing', $catalog->handle(
            Plan::forViewer($user),
            $user === null,
            $kelviq->configured(),
        ));
    }
}
