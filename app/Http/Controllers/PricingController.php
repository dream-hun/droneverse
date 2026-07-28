<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\BuildPaddleClientConfig;
use App\Actions\BuildPricingCatalog;
use App\Enums\Plan;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class PricingController extends Controller
{
    /**
     * Show what each plan costs and what it unlocks.
     *
     * Public on purpose: the page is the whole conversion argument, and a pilot
     * who has just hit a locked mission is sent here before they have any
     * reason to sign in.
     */
    public function __invoke(
        Request $request,
        BuildPricingCatalog $catalog,
        BuildPaddleClientConfig $paddle,
    ): Response {
        $user = $request->user();

        return Inertia::render('pricing', [
            ...$catalog->handle($user?->plan() ?? Plan::Starter, $user === null),
            'paddle' => $paddle->handle(),
        ]);
    }
}
