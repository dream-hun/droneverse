<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\BuildSubscriptionConfirmation;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Inertia\Inertia;
use Inertia\Response;

final class SubscriptionThankYouController extends Controller
{
    /**
     * Where a buyer lands once Lemon Squeezy reports the checkout done.
     *
     * The pricing page sends them here from the `Checkout.Success` handler
     * rather than Lemon Squeezy redirecting them: an Inertia visit swaps the
     * page under the overlay without reloading the document, so the overlay is
     * still there to be closed and the waiting for the webhook moves here,
     * where the page is built to do it. See App\Actions\StartCheckout for why
     * no `redirectTo()` is set on the checkout itself.
     *
     * Signed in, and nothing more. There is no order to authorise against —
     * that is the whole point of the page, which is often rendered before the
     * webhook has written one — so a pilot who simply visits the URL gets the
     * page too, and it tells them the truth about their own account.
     */
    public function __invoke(BuildSubscriptionConfirmation $confirmation, #[CurrentUser] User $user): Response
    {
        return Inertia::render('subscription/thank-you', $confirmation->handle($user));
    }
}
