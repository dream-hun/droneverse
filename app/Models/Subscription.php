<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\SubscriptionStatus;
use Carbon\CarbonInterface;
use Database\Factories\SubscriptionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * A Creem subscription, mirrored locally so entitlements never wait on a
 * network call.
 *
 * Every column here is written by App\Actions\SyncCreemSubscription from a
 * webhook, and nothing in the application writes one any other way: Creem is
 * the record, this is the copy, and a copy that could be edited from two
 * directions would be neither. The one exception is the moment a plan is
 * swapped, where the Action that made the change also applies it here rather
 * than leaving a pilot looking at their old plan until the webhook lands.
 *
 * @property int $id
 * @property int $billable_id
 * @property string $billable_type
 * @property string $type
 * @property string $creem_id
 * @property string $customer_id
 * @property string $product_id
 * @property string $status
 * @property int $units
 * @property CarbonInterface|null $trial_ends_at
 * @property CarbonInterface|null $renews_at
 * @property CarbonInterface|null $current_period_start_at
 * @property CarbonInterface|null $current_period_end_at
 * @property CarbonInterface|null $canceled_at
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 * @property-read Model|null $billable
 */
#[Fillable([
    'billable_id',
    'billable_type',
    'type',
    'creem_id',
    'customer_id',
    'product_id',
    'status',
    'units',
    'trial_ends_at',
    'renews_at',
    'current_period_start_at',
    'current_period_end_at',
    'canceled_at',
])]
#[Table(name: 'creem_subscriptions')]
final class Subscription extends Model
{
    /** @use HasFactory<SubscriptionFactory> */
    use HasFactory;

    /**
     * The subscription a pilot's billing is about, as opposed to any other one
     * they might hold.
     *
     * One type today. Phase 7's classroom seats are a `units` count on this
     * same row rather than a second subscription, so the constant is here to
     * name the default rather than to anticipate a second value.
     */
    public const string DEFAULT_TYPE = 'default';

    /**
     * @return MorphTo<Model, $this>
     */
    public function billable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Whether this subscription sells exactly the given Creem product.
     *
     * The comparison every "are they already on this plan?" question reduces
     * to, and the reason it is on the product ID rather than on a plan and a
     * billing period: a subscription bought at a launch price is on Pro monthly
     * by any name a page would use, and is not on the same product as one
     * bought at the standard price.
     */
    public function hasProduct(string $productId): bool
    {
        return $this->product_id === $productId;
    }

    /**
     * Whether this subscription entitles its owner to the plan it sells.
     *
     * Mostly delegated to App\Enums\SubscriptionStatus, which is where the
     * argument for each status lives. A status this application does not
     * recognise entitles nothing — a value Creem introduced after this code was
     * written is not something to grant a paid plan on the strength of.
     *
     * The one qualification the enum cannot make is the date one. A scheduled
     * cancellation entitles everything up to the end of the period that was
     * paid for and nothing after it, and Creem moves the status to `canceled`
     * when it gets there — but that is a webhook, and a webhook that never
     * arrives would otherwise leave this pilot entitled forever. So the date is
     * checked here, where the date is.
     */
    public function valid(): bool
    {
        $status = $this->status();

        if ($status === SubscriptionStatus::ScheduledCancel) {
            return ! $this->periodHasEnded();
        }

        return $status?->entitles() === true;
    }

    /**
     * Whether the subscription is winding down or already over.
     *
     * True from the moment somebody clicks cancel, including through the grace
     * period where the pilot still has everything they paid for. The billing
     * page reads it to offer resuming rather than cancelling, and to refuse a
     * plan change that would reprice something already scheduled to end.
     */
    public function cancelled(): bool
    {
        return in_array(
            $this->status(),
            [SubscriptionStatus::ScheduledCancel, SubscriptionStatus::Canceled],
            true,
        );
    }

    /**
     * Whether a cancellation has been asked for and has not taken effect yet.
     *
     * The window in which resuming is still possible. Creem's resume endpoint
     * refuses anything outside `scheduled_cancel` or `paused`, so this is also
     * what keeps that refusal out of a pilot's request.
     */
    public function onGracePeriod(): bool
    {
        return $this->status() === SubscriptionStatus::ScheduledCancel
            && ! $this->periodHasEnded();
    }

    public function paused(): bool
    {
        return $this->status() === SubscriptionStatus::Paused;
    }

    public function pastDue(): bool
    {
        return $this->status() === SubscriptionStatus::PastDue;
    }

    public function onTrial(): bool
    {
        return $this->status() === SubscriptionStatus::Trialing;
    }

    /**
     * The moment this subscription stops entitling anything, if that moment is
     * already known.
     *
     * Creem reports three dates and none of them is called "ends_at", because
     * which one ends the subscription depends on how it is ending. A scheduled
     * cancellation runs to the end of the period that was paid for. An outright
     * cancellation ended when it was cancelled. Everything else is still
     * running and has no end to report — a renewal date is not an end date, and
     * returning it here would put "ends 12 November" in front of a pilot whose
     * subscription is simply due to renew that day.
     */
    public function endsAt(): ?CarbonInterface
    {
        return match ($this->status()) {
            SubscriptionStatus::ScheduledCancel => $this->current_period_end_at,
            SubscriptionStatus::Canceled, SubscriptionStatus::Expired => $this->canceled_at ?? $this->current_period_end_at,
            default => null,
        };
    }

    /**
     * Whether the period this subscription was last paid for is behind us.
     *
     * A subscription with no period end recorded is not treated as ended.
     * Missing the date is our gap rather than the pilot's, and the failure to
     * prefer is the one that keeps somebody flying for a few more hours.
     */
    public function periodHasEnded(): bool
    {
        return $this->current_period_end_at?->isPast() === true;
    }

    /**
     * Null when the stored value names no status this application knows.
     */
    public function status(): ?SubscriptionStatus
    {
        return SubscriptionStatus::tryFrom($this->status);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'units' => 'integer',
            'trial_ends_at' => 'datetime',
            'renews_at' => 'datetime',
            'current_period_start_at' => 'datetime',
            'current_period_end_at' => 'datetime',
            'canceled_at' => 'datetime',
        ];
    }
}
