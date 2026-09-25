<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonInterface;
use Database\Factories\CustomerFactory;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * The link between one account here and one customer at Creem.
 *
 * Created by the webhook rather than by checkout, because Creem names the
 * customer when the payment succeeds and there is nothing to record before
 * then. It outlives every subscription the pilot ever holds, which is what
 * makes it the right thing to mint a customer portal link against: somebody
 * whose subscription ended last month still has invoices to download.
 *
 * @property int $id
 * @property int $billable_id
 * @property string $billable_type
 * @property string|null $creem_id
 * @property string|null $email
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 * @property-read Model|null $billable
 */
#[Table(name: 'creem_customers')]
final class Customer extends Model
{
    /** @use HasFactory<CustomerFactory> */
    use HasFactory;

    /**
     * @return MorphTo<Model, $this>
     */
    public function billable(): MorphTo
    {
        return $this->morphTo();
    }
}
