<?php

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use App\Actions\CancelSubscription;
use App\Actions\ResumeSubscription;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Laravel\Paddle\Subscription;
use Throwable;

final class SubscriptionController extends Controller
{
    /**
     * Call off a pending cancellation.
     */
    public function update(Request $request, ResumeSubscription $resume): RedirectResponse
    {
        $subscription = $this->subscriptionFor($request);

        if ($subscription === null) {
            return $this->failed(__('There is no subscription to resume.'));
        }

        try {
            $resumed = $resume->handle($request->user(), $subscription);
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
    public function destroy(Request $request, CancelSubscription $cancel): RedirectResponse
    {
        $subscription = $this->subscriptionFor($request);

        if ($subscription === null) {
            return $this->failed(__('There is no subscription to cancel.'));
        }

        try {
            $canceled = $cancel->handle($request->user(), $subscription);
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
     */
    public function edit(Request $request): RedirectResponse
    {
        $subscription = $this->subscriptionFor($request);

        if ($subscription === null) {
            return $this->failed(__('There is no subscription to update.'));
        }

        try {
            return redirect()->away($subscription->paymentMethodUpdateUrl());
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
    private function subscriptionFor(Request $request): ?Subscription
    {
        return Subscription::query()
            ->whereMorphedTo('billable', $request->user())
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
