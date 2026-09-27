<?php

declare(strict_types=1);

namespace App\Actions;

use App\Queries\KelviqEntitlements;

/**
 * Act on one verified Kelviq event.
 *
 * Kelviq is the source of truth for what a pilot may use, and every page reads
 * it through App\Queries\KelviqEntitlements. What every event about a customer
 * does is drop that customer's cached entitlements, so the change shows on
 * their next page rather than up to a minute later.
 *
 * The `order.*` events also keep the `payments` table, the local record of
 * every checkout, lifetime purchase and renewal that took money. Kelviq raises
 * an order for each of those, carrying the amount, the plan and whether it is
 * a renewal, and updates the same order when it is refunded;
 * `checkout.completed` covers the first payment only.
 *
 * The customer is the merchant-supplied id at `data.object.customer.customer_id`
 * — the pilot's uuid — which is consistent across checkout, subscription and
 * invoice events.
 */
final readonly class HandleKelviqWebhook
{
    public function __construct(
        private KelviqEntitlements $entitlements,
        private RecordKelviqOrder $orders,
    ) {
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

        if (in_array($type, ['order.created', 'order.updated', 'order.refunded'], true)) {
            /** @var array<string, mixed> $order */
            $order = data_get($event, 'data.object');
            $eventAt = $event['created_at'] ?? null;

            $this->orders->fromWebhook($order, is_string($eventAt) ? $eventAt : null);
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
            'subscription.cancelled',
            /*
             * A payment was taken, changed or refunded, and recorded above. A
             * refund can end a lifetime purchase's access, so the entitlements
             * are asked again too.
             */
            'order.created',
            'order.updated',
            'order.refunded' => $this->entitlements->forget($customerId),
            default => null,
        };
    }
}
