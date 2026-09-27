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
     * Where a buyer lands once Kelviq has taken the payment — the `success_url`
     * on every checkout this application mints.
     *
     * Signed in, and nothing more. There is no order to authorise against —
     * the page is often rendered before Kelviq has granted anything — so a
     * pilot who simply visits the URL gets the page too, and it tells them the
     * truth about their own account.
     */
    public function __invoke(BuildSubscriptionConfirmation $confirmation, #[CurrentUser] User $user): Response
    {
        return Inertia::render('subscription/thank-you', $confirmation->handle($user));
    }
}
