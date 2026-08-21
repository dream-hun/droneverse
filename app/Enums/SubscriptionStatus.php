<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Where a Creem subscription is in its life.
 *
 * The values are Creem's own wire strings, spelled Creem's way — `canceled`
 * with one L — because they arrive on a webhook and are written to a column
 * unchanged. The methods below are this application's reading of them, and the
 * two are deliberately separate: Creem says what happened, we say what it
 * entitles.
 *
 * `tryFrom()` is how a stored value is read back, which means an unfamiliar
 * status — one Creem adds after this file was written — resolves to null rather
 * than throwing on a settings page. See App\Models\Subscription, which treats
 * that as entitling nothing.
 */
enum SubscriptionStatus: string
{
    /** Current, paid, and billing normally. */
    case Active = 'active';

    /** Inside a free trial; no payment has been collected yet. */
    case Trialing = 'trialing';

    /** Cancellation requested, still running out the period already paid for. */
    case ScheduledCancel = 'scheduled_cancel';

    /** A payment failed and Creem is still retrying it. */
    case PastDue = 'past_due';

    /** Collection has given up short of cancelling. */
    case Unpaid = 'unpaid';

    /** Awaiting a payment the customer has not completed or authenticated. */
    case Incomplete = 'incomplete';

    /** Billing suspended by the merchant, resumable. */
    case Paused = 'paused';

    /** The period ended without a payment. */
    case Expired = 'expired';

    /** Over. Nothing will bill again. */
    case Canceled = 'canceled';

    /**
     * Whether a pilot in this status may reach what they paid for.
     *
     * Four yeses, and two of them are worth arguing for.
     *
     * `ScheduledCancel` entitles because the period was bought and paid for.
     * Taking the catalogue away the moment somebody clicks cancel is a refund
     * conversation, and it is the whole reason this application cancels on
     * Creem's `scheduled` mode rather than its `immediate` one. Only up to the
     * end of that period, though, and this enum holds no dates — see
     * App\Models\Subscription::valid(), which is where that half is answered.
     *
     * `PastDue` entitles because it is a payment-retry window rather than a
     * decision. A card that expired over a weekend is not a pilot who stopped
     * paying, and Creem's own guidance is to prompt for a new card rather than
     * cut access off while it retries. If every retry fails the subscription
     * moves to `Unpaid` or `Canceled`, and both of those are a no here.
     *
     * `Paused` is a no, which is the one place this differs from the Lemon
     * Squeezy integration before it. Lemon Squeezy's free pause left a
     * subscription valid; Creem pauses billing and expects access to stop with
     * it. Nothing in this application pauses a subscription, so the case only
     * arises from the Creem dashboard — and somebody pausing billing there
     * means to stop the service, not to give it away.
     */
    public function entitles(): bool
    {
        return match ($this) {
            self::Active, self::Trialing, self::ScheduledCancel, self::PastDue => true,
            self::Unpaid, self::Incomplete, self::Paused, self::Expired, self::Canceled => false,
        };
    }
}
