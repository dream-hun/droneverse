<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonInterface;
use Database\Factories\PaymentFactory;
use Illuminate\Database\Eloquent\Attributes\DateFormat;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One Kelviq order — a checkout, a lifetime purchase or a renewal — as Kelviq
 * last described it.
 *
 * Written only by {@see \App\Actions\RecordKelviqOrder}, from the `order.*`
 * webhooks and from `kelviq:sync-payments`. It is a record of what was paid,
 * never an answer to what a pilot may use: that is still
 * {@see \App\Queries\KelviqEntitlements}.
 *
 * Dates are stored to the microsecond. `kelviq_updated_at` is what keeps an
 * older description of an order from overwriting a newer one, and Kelviq's
 * times carry microseconds: cut to the second, two changes made within one
 * second could not be told apart.
 *
 * @property int $id
 * @property string $kelviq_order_id
 * @property int|null $user_id
 * @property string $kelviq_customer_id
 * @property string|null $kelviq_subscription_id
 * @property string $status
 * @property string|null $billing_type
 * @property bool|null $is_renewal
 * @property string|null $plan_identifier
 * @property int|null $amount_units
 * @property string|null $currency
 * @property CarbonInterface|null $paid_at
 * @property CarbonInterface|null $kelviq_updated_at
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 * @property-read User|null $user
 */
#[DateFormat('Y-m-d H:i:s.u')]
final class Payment extends Model
{
    /** @use HasFactory<PaymentFactory> */
    use HasFactory;

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_renewal' => 'boolean',
            'amount_units' => 'integer',
            'paid_at' => 'datetime',
            'kelviq_updated_at' => 'datetime',
        ];
    }
}
