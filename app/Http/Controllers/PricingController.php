<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\BuildLemonSqueezyClientConfig;
use App\Actions\BuildPricingCatalog;
use App\Enums\Plan;
use App\Queries\DefaultSubscription;
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
        BuildLemonSqueezyClientConfig $lemonSqueezy,
        DefaultSubscription $subscriptions,
    ): Response {
        $user = $request->user();

        /*
         * A subscriber's buttons move the subscription they have rather than
         * opening a second checkout. The two answers differ for one pilot only —
         * the one whose subscription is cancelled and running out its grace
         * period, who can neither switch nor be sold a second subscription
         * beside a live one — and telling them apart is the whole reason the
         * catalogue is told both.
         */
        $subscription = $subscriptions->for($user);

        return Inertia::render('pricing', [
            ...$catalog->handle(
                $user?->plan() ?? Plan::Starter,
                $user === null,
                $subscription?->valid() === true,
                $subscriptions->isSwitchable($subscription),
            ),
            'lemonSqueezy' => $lemonSqueezy->handle(),
        ]);
    }
}
