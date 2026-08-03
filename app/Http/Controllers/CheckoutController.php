<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\StartCheckout;
use App\Http\Requests\CheckoutRequest;
use App\Models\User;
use App\Queries\DefaultSubscription;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;
use Throwable;

final class CheckoutController extends Controller
{
    /**
     * Open a Lemon Squeezy checkout for the plan the pilot picked.
     *
     * Answers JSON rather than an Inertia response: the pricing page stays
     * where it is and hands the URL straight to the Lemon.js overlay, so a
     * pilot who closes the overlay is back on the page they were reading rather
     * than on a re-rendered copy of it.
     */
    public function store(
        CheckoutRequest $request,
        StartCheckout $checkout,
        DefaultSubscription $subscriptions,
        #[CurrentUser] User $user,
    ): JsonResponse {
        $plan = $request->plan();

        /*
         * Checkout sells first subscriptions only. A pilot who already holds one
         * is changing what it sells, not buying a second: two live subscriptions
         * means two charges, two renewal dates and no more access than one of
         * them already granted. The pricing page routes a subscriber to the swap
         * endpoint for exactly this reason, and this is what makes that true of
         * a request that skipped the page.
         */
        if ($subscriptions->for($user)?->valid() === true) {
            throw ValidationException::withMessages([
                'plan' => __('You already have a subscription. Change your plan from billing settings.'),
            ]);
        }

        try {
            $url = $checkout->handle($user, $plan, $request->variant());
        } catch (Throwable) {
            /*
             * Minting a checkout is a live API call now, so this endpoint can
             * fail for reasons that have nothing to do with what was posted:
             * an unset API key, a store that is not configured, Lemon Squeezy
             * being down. None of those are a 500 the pilot can act on, and
             * none of them are theirs to read — the exception message names our
             * provider and our configuration. It comes back as a validation
             * error on `plan`, which the pricing page already renders as a
             * toast beside the button that was clicked.
             */
            throw ValidationException::withMessages([
                'plan' => __('Checkout could not be opened right now. Please try again in a moment.'),
            ]);
        }

        if ($url === null) {
            /*
             * Reached by an unsold billing period, a sales-led tier, or a price
             * ID this environment has not configured. All three are "you cannot
             * buy this", and none of them should say which — the difference is
             * our configuration, not the buyer's business.
             */
            throw ValidationException::withMessages([
                'plan' => __(':plan is not available for purchase right now.', ['plan' => $plan->label()]),
            ]);
        }

        return response()->json(['checkout' => ['url' => $url]]);
    }
}
