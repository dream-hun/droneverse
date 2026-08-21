<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\Customer;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Database\Eloquent\Relations\Relation;

/**
 * Which account a Creem webhook is about.
 *
 * Four ways of asking, tried in order of how much they prove, because a payload
 * that arrives without the first one is not necessarily a payload we cannot
 * place. The Lemon Squeezy integration this replaces had only the metadata
 * branch and answered a `400` to everything else, which Lemon Squeezy retried
 * for days — a subscription created by hand in the dashboard could never be
 * recorded, and every attempt to record it left an orphan customer row behind.
 *
 *   1. A subscription we have already recorded. The strongest answer available:
 *      the row was written by an earlier delivery we did place, and the account
 *      on it cannot have been guessed.
 *   2. The metadata App\Actions\StartCheckout writes. Every subscription bought
 *      through this application carries it, on the checkout and on the
 *      subscription Creem copies it onto.
 *   3. A customer we have already recorded, by Creem's ID for them.
 *   4. The customer's email address, matched against an account.
 *
 * Null means none of them landed, which is a real outcome rather than an error:
 * a payment link shared outside the application, or a subscription created in
 * the Creem dashboard for somebody who has never signed up here, belongs to
 * nobody we can grant anything to. The caller acknowledges it and moves on
 * rather than making Creem redeliver something that will never resolve.
 *
 * The email branch is last on purpose. It is the only one that infers identity
 * from something a buyer types, and Creem locks the email at checkout to the
 * one we send precisely so that it agrees with the account — but a payment made
 * outside that flow can carry any address at all, so it is the fallback rather
 * than the rule.
 */
final readonly class ResolveCreemBillable
{
    /**
     * Creem sends nested objects either expanded or as a bare ID string,
     * depending on the event. Both mean the same thing here.
     *
     * @param  array<string, mixed>  $object
     */
    public static function id(array $object, string $key): ?string
    {
        $value = $object[$key] ?? null;

        if (is_array($value)) {
            $value = $value['id'] ?? null;
        }

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * @param  array<string, mixed>  $object  The `object` of a webhook event: a
     *                                        subscription, a checkout or a refund.
     */
    public function handle(array $object): ?User
    {
        return $this->fromRecordedSubscription($object)
            ?? $this->fromMetadata($object)
            ?? $this->fromRecordedCustomer($object)
            ?? $this->fromCustomerEmail($object);
    }

    /**
     * @param  array<string, mixed>  $object
     */
    private function fromRecordedSubscription(array $object): ?User
    {
        $subscriptionId = self::id($object, 'subscription')
            ?? (($object['object'] ?? null) === 'subscription' ? self::id($object, 'id') : null);

        if ($subscriptionId === null) {
            return null;
        }

        $billable = Subscription::query()
            ->where('creem_id', $subscriptionId)
            ->first()?->billable;

        return $billable instanceof User ? $billable : null;
    }

    /**
     * @param  array<string, mixed>  $object
     */
    private function fromMetadata(array $object): ?User
    {
        $type = data_get($object, 'metadata.billable_type');
        $id = data_get($object, 'metadata.billable_id');

        if (! is_string($type) || $type === '' || (! is_string($id) && ! is_int($id))) {
            return null;
        }

        /*
         * Resolved through the morph map rather than by treating the value as a
         * class name, because that is how the value was written —
         * `getMorphClass()` answers with the alias whenever one is registered —
         * and because a signed payload is still the wrong place to be handed an
         * arbitrary class to instantiate.
         */
        $class = Relation::getMorphedModel($type) ?? $type;

        if ($class !== User::class) {
            return null;
        }

        return User::query()->whereKey($id)->first();
    }

    /**
     * @param  array<string, mixed>  $object
     */
    private function fromRecordedCustomer(array $object): ?User
    {
        $customerId = self::id($object, 'customer');

        if ($customerId === null) {
            return null;
        }

        $billable = Customer::query()
            ->where('creem_id', $customerId)
            ->first()?->billable;

        return $billable instanceof User ? $billable : null;
    }

    /**
     * @param  array<string, mixed>  $object
     */
    private function fromCustomerEmail(array $object): ?User
    {
        $email = data_get($object, 'customer.email');

        if (! is_string($email) || $email === '') {
            return null;
        }

        return User::query()->where('email', $email)->first();
    }
}
