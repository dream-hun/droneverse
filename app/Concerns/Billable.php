<?php

declare(strict_types=1);

namespace App\Concerns;

use App\Models\Customer;
use App\Models\Order;
use App\Models\Subscription;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;

/**
 * What it means for a model to be something Creem can bill.
 *
 * Three relations and nothing else. The Lemon Squeezy package this replaces put
 * checkout, plan swapping and cancellation on the billable too, so a controller
 * could charge a card by calling a method on a user; here those live in Actions
 * that take a subscription, which is what keeps the money out of the model.
 *
 * Morph relations rather than foreign keys, because the tables are written by a
 * webhook that names its account by type and id — Phase 7's classroom is a
 * second kind of billable, and it should not need a column of its own.
 *
 * @mixin Model
 */
trait Billable
{
    /**
     * The Creem customer this account is, once Creem has named one.
     *
     * Absent until the first payment lands: the webhook creates it, checkout
     * does not. Code that needs the customer to exist has to say what it does
     * when it does not.
     *
     * @return MorphOne<Customer, $this>
     */
    public function customer(): MorphOne
    {
        return $this->morphOne(Customer::class, 'billable');
    }

    /**
     * @return MorphMany<Subscription, $this>
     */
    public function subscriptions(): MorphMany
    {
        return $this->morphMany(Subscription::class, 'billable');
    }

    /**
     * @return MorphMany<Order, $this>
     */
    public function orders(): MorphMany
    {
        return $this->morphMany(Order::class, 'billable');
    }
}
