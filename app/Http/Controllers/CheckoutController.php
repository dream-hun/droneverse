<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\StartCheckout;
use App\Http\Requests\CheckoutRequest;
use App\Models\User;
use App\Queries\KelviqEntitlements;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

final class CheckoutController extends Controller
{
    /**
     * Send the pilot to a Kelviq checkout for the plan and period they picked.
     *
     * Inertia::location() rather than a plain away-redirect: both upgrade
     * buttons post here with Inertia, which is an XHR, and Kelviq's HTML would
     * come back without the X-Inertia header. This answers an Inertia visit
     * with the 409 and X-Inertia-Location it navigates on, and a plain request
     * with the 302 it wants.
     */
    public function store(
        CheckoutRequest $request,
        StartCheckout $checkout,
        KelviqEntitlements $entitlements,
        #[CurrentUser] User $user,
    ): Response {
        $plan = $request->plan();

        /*
         * Checkout sells first subscriptions only. A pilot already on a paid
         * plan is changing what they have, not buying a second one, and Kelviq's
         * portal is where that happens. Asked of Kelviq afresh rather than of
         * the cache, so a pilot who paid a minute ago is not sold the same plan
         * again on a stale answer.
         */
        $entitlements->forget($user->uuid);

        if ($user->forgetPlan()->plan()->isPaid()) {
            return $this->failed(__('You are already on :plan. Manage it from billing settings.', ['plan' => $user->plan()->label()]), to_route('billing.edit'));
        }

        try {
            $url = $checkout->handle($user, $plan, $request->variant());
        } catch (Throwable) {
            /*
             * Minting a checkout is a live API call, so this can fail for
             * reasons that have nothing to do with what was posted: a bad key,
             * a plan the catalog does not have, Kelviq being down. None of them
             * are a 500 the pilot can act on, and none of them are theirs to
             * read — the exception message names our provider and our
             * configuration.
             */
            return $this->failed(__('Checkout could not be opened right now. Please try again in a moment.'));
        }

        if ($url === null) {
            return $this->failed(__(':plan is not available for purchase right now.', ['plan' => $plan->label()]));
        }

        return Inertia::location($url);
    }

    private function failed(string $message, ?RedirectResponse $to = null): RedirectResponse
    {
        Inertia::flash('toast', ['type' => 'error', 'message' => $message]);

        return $to ?? back();
    }
}
