<?php

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use App\Actions\CancelSubscription;
use App\Actions\ResumeSubscription;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Laravel\Paddle\Subscription;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

final class SubscriptionController extends Controller
{
    /**
     * Call off a pending cancellation.
     */
    public function update(#[CurrentUser] User $user, ResumeSubscription $resume): RedirectResponse
    {
        $subscription = $this->subscriptionFor($user);

        if (! $subscription instanceof Subscription) {
            return $this->failed(__('There is no subscription to resume.'));
        }

        try {
            $resumed = $resume->handle($user, $subscription);
        } catch (Throwable) {
            return $this->failed(__('Paddle could not resume your subscription. Please try again.'));
        }

        if (! $resumed) {
            return $this->failed(__('That subscription has already ended. Start a new one from the pricing page.'));
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Your subscription will continue.')]);

        return to_route('billing.edit');
    }

    /**
     * Cancel at the end of the current billing period.
     */
    public function destroy(#[CurrentUser] User $user, CancelSubscription $cancel): RedirectResponse
    {
        $subscription = $this->subscriptionFor($user);

        if (! $subscription instanceof Subscription) {
            return $this->failed(__('There is no subscription to cancel.'));
        }

        try {
            $canceled = $cancel->handle($user, $subscription);
        } catch (Throwable) {
            return $this->failed(__('Paddle could not cancel your subscription. Please try again.'));
        }

        if (! $canceled) {
            return $this->failed(__('That subscription is already scheduled to end.'));
        }

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('Cancelled. You keep everything until the end of the period you have paid for.'),
        ]);

        return to_route('billing.edit');
    }

    /**
     * Send the pilot to Paddle's hosted page for changing the card on file.
     *
     * Hosted rather than an overlay of our own: card details should never touch
     * a page we render, and the URL is single-use and subscription-scoped.
     *
     * Inertia::location() rather than a plain away-redirect: the billing page
     * reaches this with an Inertia <Link>, which is an XHR. The browser follows
     * a 302 transparently, and Paddle's HTML comes back without the X-Inertia
     * header — Inertia rejects that as an invalid response instead of
     * navigating. This answers an Inertia visit with the 409 and
     * X-Inertia-Location it acts on, and a plain request with the 302 it wants.
     */
    public function edit(#[CurrentUser] User $user): Response
    {
        $subscription = $this->subscriptionFor($user);

        if (! $subscription instanceof Subscription) {
            return $this->failed(__('There is no subscription to update.'));
        }

        try {
            return Inertia::location($subscription->paymentMethodUpdateUrl());
        } catch (Throwable) {
            return $this->failed(__('Paddle could not open the payment method page. Please try again.'));
        }
    }

    /**
     * The pilot's own default subscription, or null.
     *
     * Scoped to the authenticated user rather than resolved from a route
     * parameter, so there is no subscription identifier for a request to
     * substitute someone else's.
     */
    private function subscriptionFor(User $user): ?Subscription
    {
        return Subscription::query()
            ->whereMorphedTo('billable', $user)
            ->where('type', Subscription::DEFAULT_TYPE)
            ->latest('id')
            ->first();
    }

    private function failed(string $message): RedirectResponse
    {
        Inertia::flash('toast', ['type' => 'error', 'message' => $message]);

        return to_route('billing.edit');
    }
}
