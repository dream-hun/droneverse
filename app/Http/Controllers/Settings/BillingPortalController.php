<?php

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Integrations\Kelviq;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

final class BillingPortalController extends Controller
{
    /**
     * Send the pilot to Kelviq's customer portal.
     *
     * The portal is where everything about an existing subscription happens:
     * the card on file, invoices, moving between monthly and yearly, and
     * cancelling. Hosted rather than rebuilt here, so card details never touch
     * a page this application renders.
     *
     * The session is minted per request and redirected to rather than stored,
     * signed by appending its token to the portal URL as Kelviq documents.
     *
     * Inertia::location() for the same reason as checkout: the billing page
     * reaches this with an Inertia <Link>, which is an XHR.
     */
    public function edit(#[CurrentUser] User $user, Kelviq $kelviq): Response
    {
        if (! $kelviq->configured()) {
            return $this->failed(__('There is no billing account to manage yet.'));
        }

        try {
            $session = $kelviq->createPortalSession($user->uuid);
        } catch (RequestException $exception) {
            /*
             * 400 is Kelviq's answer for a customer it has never seen, and for
             * one it holds without an email address — somebody who has never
             * reached checkout. That is a sentence to show, not a 500.
             */
            return $this->failed($exception->response->badRequest()
                ? __('There is no billing account to manage yet. It appears once you upgrade.')
                : __('The billing portal could not be opened. Please try again.'));
        } catch (Throwable) {
            return $this->failed(__('The billing portal could not be opened. Please try again.'));
        }

        return Inertia::location($session['customerPortalUrl'].'?token='.$session['token']);
    }

    private function failed(string $message): RedirectResponse
    {
        Inertia::flash('toast', ['type' => 'error', 'message' => $message]);

        return to_route('billing.edit');
    }
}
