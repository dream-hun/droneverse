<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\User;
use Illuminate\Support\Facades\Log;

/**
 * Everything a Creem webhook can mean to this application.
 *
 * Creem publishes thirteen events; this handles eleven of them, and the two it
 * ignores are ignored on purpose. `dispute.created` is a chargeback, which is a
 * conversation with Creem rather than a change to what a pilot may fly — the
 * subscription event that follows it is what moves the entitlement.
 *
 * The subscription events are one branch rather than eleven, because they carry
 * the same object and differ only in why it was sent. Which of them entitles
 * what is App\Enums\SubscriptionStatus's question, asked of the status on the
 * row, and asking it there rather than here means a status arriving on an event
 * we did not expect still lands somewhere sensible.
 *
 * Nothing in here throws for a payload it cannot place. Creem redelivers
 * anything that is not a 2xx — five attempts across six hours — and a webhook
 * naming an account that does not exist will name it just as absently on the
 * fifth attempt as on the first. Those are logged and acknowledged.
 */
final readonly class HandleCreemWebhook
{
    public function __construct(
        private ResolveCreemBillable $billables,
        private SyncCreemSubscription $subscriptions,
        private SyncCreemOrder $orders,
        private RecordCreemRefund $refunds,
    ) {
        //
    }

    /**
     * @param  array<string, mixed>  $payload  The decoded webhook body.
     */
    public function handle(array $payload): void
    {
        $event = $payload['eventType'] ?? null;
        $object = $payload['object'] ?? null;

        if (! is_string($event) || $event === '' || ! is_array($object)) {
            return;
        }

        /** @var array<string, mixed> $object */
        match ($event) {
            'checkout.completed' => $this->checkoutCompleted($event, $object),
            'subscription.active',
            'subscription.paid',
            'subscription.trialing',
            'subscription.update',
            'subscription.canceled',
            'subscription.scheduled_cancel',
            'subscription.past_due',
            'subscription.unpaid',
            'subscription.expired',
            'subscription.paused' => $this->subscriptionChanged($event, $object),
            'refund.created' => $this->refunds->handle($object),
            default => null,
        };
    }

    /**
     * A payment landed: record the receipt, and the subscription if it started
     * one.
     *
     * Both, from one event, because this is the earliest either can be known
     * and the buyer is already looking at the thank-you page waiting for the
     * second. `subscription.active` says the same thing about the subscription
     * a moment later and writes the same row again; neither is waiting on the
     * other.
     *
     * @param  array<string, mixed>  $checkout
     */
    private function checkoutCompleted(string $event, array $checkout): void
    {
        $user = $this->billable($event, $checkout);

        if (! $user instanceof User) {
            return;
        }

        $this->orders->handle($user, $checkout);

        $subscription = $checkout['subscription'] ?? null;

        if (is_array($subscription)) {
            /** @var array<string, mixed> $subscription */
            $this->subscriptions->handle($user, $subscription);
        }
    }

    /**
     * @param  array<string, mixed>  $subscription
     */
    private function subscriptionChanged(string $event, array $subscription): void
    {
        $user = $this->billable($event, $subscription);

        if (! $user instanceof User) {
            return;
        }

        $this->subscriptions->handle($user, $subscription);
    }

    /**
     * @param  array<string, mixed>  $object
     */
    private function billable(string $event, array $object): ?User
    {
        $user = $this->billables->handle($object);

        if (! $user instanceof User) {
            /*
             * Logged rather than swallowed, because the two things this can
             * mean are very different. A payment link shared outside the
             * application is a sale nobody here can be granted anything for and
             * is entirely expected. Metadata missing from a checkout this
             * application opened is a bug, and somebody has paid for something
             * they will not receive — which is only ever visible here.
             */
            Log::warning('Acknowledged a Creem webhook naming no account in this application.', [
                'event' => $event,
                'object' => ResolveCreemBillable::id($object, 'id'),
                'customer' => ResolveCreemBillable::id($object, 'customer'),
            ]);
        }

        return $user;
    }
}
