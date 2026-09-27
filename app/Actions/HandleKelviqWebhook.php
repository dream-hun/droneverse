<?php

declare(strict_types=1);

namespace App\Actions;

use App\Queries\KelviqEntitlements;

/**
 * Act on one verified Kelviq event.
 *
 * Kelviq is the source of truth for who has paid for what, and every page reads
 * it through App\Queries\KelviqEntitlements, so there is no local billing copy
 * for an event to update. What every event about a customer does do is drop
 * that customer's cached entitlements, so the change shows on their next page
 * rather than up to a minute later.
 *
 * The customer is the merchant-supplied id at `data.object.customer.customer_id`
 * — the pilot's uuid — which is consistent across checkout, subscription and
 * invoice events.
 */
final readonly class HandleKelviqWebhook
{
    public function __construct(private KelviqEntitlements $entitlements)
    {
        //
    }

    /**
     * @param  array<string, mixed>  $event
     */
    public function handle(array $event): void
    {
        $type = $event['type'] ?? null;
        $customerId = data_get($event, 'data.object.customer.customer_id');

        if (! is_string($customerId) || $customerId === '') {
            return;
        }

        match ($type) {
            /*
             * TODO: payment is collected. The subscription itself arrives in
             * subscription.created; send a receipt or a welcome from here.
             */
            'checkout.completed',
            /*
             * TODO: a renewal failed. Kelviq retries for up to 14 days before
             * cancelling, and access stays on meanwhile; tell the pilot to
             * update their card in the portal.
             */
            'invoice.payment_failed',
            // TODO: a new subscription is active.
            'subscription.created',
            /*
             * TODO: a subscription changed. This is also what fires when a
             * cancellation is scheduled — status stays active and `end_date` is
             * set — so a "sorry to see you go" belongs here, not below.
             */
            'subscription.updated',
            // TODO: the subscription moved to another plan or billing period.
            'subscription.plan_changed',
            /*
             * TODO: the subscription has actually ended, not merely been
             * scheduled to. Entitlements are already gone on Kelviq's side.
             */
            'subscription.cancelled' => $this->entitlements->forget($customerId),
            default => null,
        };
    }
}
