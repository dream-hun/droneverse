<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Actions\CancelSubscription;
use App\Http\Controllers\Controller;
use App\Models\Subscription;
use App\Models\User;
use App\Queries\DefaultSubscription;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Throwable;

final class UserSubscriptionController extends Controller
{
    /**
     * Cancel a pilot's subscription on their behalf.
     *
     * The same scheduled cancellation the pilot's own billing page makes: it
     * stops the next charge and leaves them everything they have paid for up
     * to the end of the period. That is the step deleting an account waits
     * on — App\Actions\DeleteUser refuses an account Creem is still billing,
     * and one winding down no longer is.
     *
     * Guarded like an edit as well as by `view_finance` on the route, so a
     * member of staff can only cancel for an account they could edit.
     */
    public function destroy(User $user, DefaultSubscription $subscriptions, CancelSubscription $cancel): RedirectResponse
    {
        Gate::authorize('update', $user);

        $subscription = $subscriptions->for($user);

        if (! $subscription instanceof Subscription || ! $subscriptions->isSwitchable($subscription)) {
            return $this->failed(__('This account has no subscription that is still billing.'));
        }

        try {
            $cancel->handle($user, $subscription);
        } catch (Throwable) {
            return $this->failed(__('Creem could not cancel the subscription. Please try again.'));
        }

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('Cancelled. They keep access until the end of the period they paid for, and the account can now be deleted.'),
        ]);

        return back();
    }

    private function failed(string $message): RedirectResponse
    {
        Inertia::flash('toast', ['type' => 'error', 'message' => $message]);

        return back();
    }
}
