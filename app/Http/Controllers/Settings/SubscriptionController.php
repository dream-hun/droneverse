<?php

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use App\Actions\CancelSubscription;
use App\Actions\ResumeSubscription;
use App\Actions\SwapSubscription;
use App\Enums\Plan;
use App\Enums\PlanChange;
use App\Http\Controllers\Controller;
use App\Http\Requests\ChangePlanRequest;
use App\Models\User;
use App\Queries\DefaultSubscription;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use LemonSqueezy\Laravel\Subscription;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

final class SubscriptionController extends Controller
{
    public function __construct(private readonly DefaultSubscription $subscriptions)
    {
        //
    }

    /**
     * Call off a pending cancellation.
     */
    public function update(#[CurrentUser] User $user, ResumeSubscription $resume): RedirectResponse
    {
        $subscription = $this->subscriptions->for($user);

        if (! $subscription instanceof Subscription) {
            return $this->failed(__('There is no subscription to resume.'));
        }

        try {
            $resumed = $resume->handle($user, $subscription);
        } catch (Throwable) {
            return $this->failed(__('Lemon Squeezy could not resume your subscription. Please try again.'));
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
        $subscription = $this->subscriptions->for($user);

        if (! $subscription instanceof Subscription) {
            return $this->failed(__('There is no subscription to cancel.'));
        }

        try {
            $cancelled = $cancel->handle($user, $subscription);
        } catch (Throwable) {
            return $this->failed(__('Lemon Squeezy could not cancel your subscription. Please try again.'));
        }

        if (! $cancelled) {
            return $this->failed(__('That subscription is already scheduled to end.'));
        }

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('Cancelled. You keep everything until the end of the period you have paid for.'),
        ]);

        return to_route('billing.edit');
    }

    /**
     * Send the pilot to Lemon Squeezy's hosted page for changing the card on file.
     *
     * Hosted rather than an overlay of our own: card details should never touch
     * a page we render, and the URL is minted per request and subscription-scoped.
     *
     * Inertia::location() rather than a plain away-redirect: the billing page
     * reaches this with an Inertia <Link>, which is an XHR. The browser follows
     * a 302 transparently, and Lemon Squeezy's HTML comes back without the X-Inertia
     * header — Inertia rejects that as an invalid response instead of
     * navigating. This answers an Inertia visit with the 409 and
     * X-Inertia-Location it acts on, and a plain request with the 302 it wants.
     */
    public function edit(#[CurrentUser] User $user): Response
    {
        $subscription = $this->subscriptions->for($user);

        if (! $subscription instanceof Subscription) {
            return $this->failed(__('There is no subscription to update.'));
        }

        try {
            return Inertia::location($subscription->updatePaymentMethodUrl());
        } catch (Throwable) {
            return $this->failed(__('Lemon Squeezy could not open the payment method page. Please try again.'));
        }
    }

    /**
     * Move to another plan or billing period, keeping one subscription.
     *
     * Upgrades, downgrades and monthly-to-yearly are all this one route: which
     * direction the move goes changes what Lemon Squeezy prorates, and nothing
     * else. A subscriber never buys a second subscription to change tier — see
     * App\Actions\SwapSubscription for what that would cost them.
     */
    public function swap(ChangePlanRequest $request, #[CurrentUser] User $user, SwapSubscription $swap): RedirectResponse
    {
        $subscription = $this->subscriptions->for($user);

        if (! $subscription instanceof Subscription || ! $subscription->valid()) {
            return $this->failed(__('There is no subscription to change. Pick a plan from the pricing page.'));
        }

        /*
         * A cancelled subscription is still valid through its grace period, but
         * repricing something already scheduled to end takes money for a plan
         * the pilot has said they do not want. Resuming is one button away, and
         * the billing page offers it beside this one.
         */
        if ($subscription->cancelled()) {
            return $this->failed(__('Resume your subscription before changing plan.'));
        }

        $plan = $request->plan();

        try {
            $change = $swap->handle($user, $subscription, $plan, $request->variant());
        } catch (Throwable) {
            /*
             * A live API call, so it fails for reasons that are none of the
             * pilot's business: an unset key, a variant the store does not have,
             * Lemon Squeezy being down. The subscription is untouched either way.
             */
            return $this->failed(__('Lemon Squeezy could not change your plan. Please try again.'));
        }

        return match ($change) {
            PlanChange::Unchanged => $this->failed(__('You are already on that plan.')),
            PlanChange::Unavailable => $this->failed(__(':plan is not available to switch to right now.', ['plan' => $plan->label()])),
            PlanChange::Swapped => $this->changed($plan),
        };
    }

    /**
     * Report a completed switch.
     *
     * It says what will happen to the money, because the button gave no warning
     * about it: nothing is charged today, and the difference lands on the next
     * renewal. A pilot who reads "you're on Team now" and nothing else is left
     * wondering what their card just did.
     */
    private function changed(Plan $plan): RedirectResponse
    {
        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('You are on :plan now. The difference is settled on your next renewal.', ['plan' => $plan->label()]),
        ]);

        return to_route('billing.edit');
    }

    private function failed(string $message): RedirectResponse
    {
        Inertia::flash('toast', ['type' => 'error', 'message' => $message]);

        return to_route('billing.edit');
    }
}
