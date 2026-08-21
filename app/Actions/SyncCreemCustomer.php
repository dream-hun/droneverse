<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\Customer;
use App\Models\User;

/**
 * Record which Creem customer an account is.
 *
 * Written from whichever webhook happens to mention the customer first, and
 * rewritten by every one after it, so the row keeps up with an email changed on
 * Creem's side. Keyed on the billable rather than on Creem's ID: an account has
 * one customer, and a second ID arriving for it is a correction rather than a
 * second row.
 */
final readonly class SyncCreemCustomer
{
    /**
     * @param  array<string, mixed>  $object  A webhook event's `object`, whose
     *                                        `customer` may be expanded or a bare ID.
     */
    public function handle(User $user, array $object): ?Customer
    {
        $customerId = ResolveCreemBillable::id($object, 'customer');

        if ($customerId === null) {
            return null;
        }

        $email = data_get($object, 'customer.email');

        return Customer::query()->updateOrCreate(
            [
                'billable_id' => $user->getKey(),
                'billable_type' => $user->getMorphClass(),
            ],
            array_filter([
                'creem_id' => $customerId,
                'email' => is_string($email) && $email !== '' ? $email : null,
            ], static fn (mixed $value): bool => $value !== null),
        );
    }
}
