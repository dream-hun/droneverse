<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonInterface;
use Database\Factories\OrderFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * One completed Creem order, which is to say one receipt.
 *
 * Written by `checkout.completed` and amended by `refund.created`. Creem
 * publishes no per-order receipt URL — invoices are behind the customer portal,
 * reached by a magic link minted per request — so the billing page renders the
 * money from these columns and links to the portal once for the documents.
 *
 * @property int $id
 * @property int $billable_id
 * @property string $billable_type
 * @property string $creem_id
 * @property string|null $checkout_id
 * @property string $customer_id
 * @property string $product_id
 * @property string|null $subscription_id
 * @property string $currency
 * @property int $amount
 * @property string $status
 * @property string|null $type
 * @property bool $refunded
 * @property int|null $refunded_amount
 * @property CarbonInterface|null $refunded_at
 * @property CarbonInterface $ordered_at
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 * @property-read Model|null $billable
 */
#[Fillable([
    'billable_id',
    'billable_type',
    'creem_id',
    'checkout_id',
    'customer_id',
    'product_id',
    'subscription_id',
    'currency',
    'amount',
    'status',
    'type',
    'refunded',
    'refunded_amount',
    'refunded_at',
    'ordered_at',
])]
#[Table(name: 'creem_orders')]
final class Order extends Model
{
    /** @use HasFactory<OrderFactory> */
    use HasFactory;

    /**
     * @return MorphTo<Model, $this>
     */
    public function billable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'refunded' => 'boolean',
            'refunded_amount' => 'integer',
            'refunded_at' => 'datetime',
            'ordered_at' => 'datetime',
        ];
    }
}
