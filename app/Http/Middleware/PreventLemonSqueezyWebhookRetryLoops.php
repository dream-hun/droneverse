<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use LemonSqueezy\Laravel\LemonSqueezy;
use Symfony\Component\HttpFoundation\Response;

/**
 * Acknowledge the deliveries that retrying will never fix.
 *
 * Lemon Squeezy retries anything that does not answer 2xx, and the package's
 * webhook controller answers non-2xx for two situations that are not transient
 * at all: one that has already succeeded, and one that never can. Neither
 * improves on the tenth attempt, so both are acknowledged here and the
 * controller is not reached.
 *
 * **Already recorded.** `subscription_created` and `order_created` are the only
 * two handlers that insert rather than sync — every other handler looks its row
 * up first and calls `sync()`, which is naturally repeatable. Both insert
 * against a unique `lemon_squeezy_id`, so a redelivery raises a QueryException,
 * which is neither of the two throwables the controller special-cases and so
 * comes back as `500 Internal server error`. Lemon Squeezy then redelivers the
 * same payload into the same constraint. The row is already there and the work
 * is already done; the only thing missing is somebody saying so.
 *
 * **Names nobody we can reach.** The four handlers that call `resolveBillable()`
 * need `billable_id` and `billable_type` in `custom_data`, which only
 * App\Actions\StartCheckout sets. A subscription created by hand in the Lemon
 * Squeezy dashboard, or bought through a payment link shared outside this
 * application, carries no custom data at all and raises InvalidCustomPayload —
 * a `400`, which Lemon Squeezy retries just as willingly as a 500. Worse is a
 * payload naming a billable that no longer exists: `firstOrCreate` writes a
 * customer row pointing at nothing, `->billable` comes back null, and the
 * method call on it raises an `Error` — which the controller does not catch at
 * all, because it catches `Exception`. That one leaves an orphan customer row
 * behind on every attempt.
 *
 * This runs behind VerifyLemonSqueezyWebhookSignature and reads the body only
 * once it has been authenticated, so nothing unsigned reaches these queries. It
 * deliberately decides nothing about events it does not name: an unfamiliar
 * event is the controller's business, and it already acknowledges the ones it
 * has no handler for.
 *
 * What this cannot do is serialise two deliveries racing each other, where both
 * find no row and both go on to insert. That is what the unique index is for.
 * The loser still answers 500 once, and the redelivery it earns is caught by
 * the check below rather than by the constraint again.
 */
final class PreventLemonSqueezyWebhookRetryLoops
{
    public function handle(Request $request, Closure $next): Response
    {
        /** @var array<string, mixed> $payload */
        $payload = $request->all();

        $event = data_get($payload, 'meta.event_name');

        if (! is_string($event) || $event === '') {
            return $next($request);
        }

        if ($this->alreadyRecorded($event, $payload)) {
            return new Response('Webhook already handled.');
        }

        if ($this->namesNoReachableBillable($event, $payload)) {
            Log::warning('Acknowledged a Lemon Squeezy webhook naming no reachable account.', [
                'event' => $event,
                'id' => data_get($payload, 'data.id'),
                'billable_type' => data_get($payload, 'meta.custom_data.billable_type'),
                'billable_id' => data_get($payload, 'meta.custom_data.billable_id'),
            ]);

            return new Response('Webhook received but it names no account in this application.');
        }

        return $next($request);
    }

    /**
     * The events whose handlers insert a row keyed by `data.id`, against the
     * model that holds it.
     *
     * Read off LemonSqueezy rather than named directly so that swapping a model
     * through `useSubscriptionModel()` moves this check with it. Those
     * properties are plain strings and the setters check nothing, so what comes
     * back is a class name by convention rather than by type — hence the guard
     * where it is used.
     *
     * @return array<string, string>
     */
    private function recordedBy(): array
    {
        return [
            'subscription_created' => LemonSqueezy::$subscriptionModel,
            'order_created' => LemonSqueezy::$orderModel,
        ];
    }

    /**
     * The events whose handlers call `resolveBillable()` before anything else,
     * and so cannot run at all without custom data naming a real account.
     *
     * @return array<int, string>
     */
    private function requiringBillable(): array
    {
        return [
            'subscription_created',
            'order_created',
            'order_refunded',
            'license_key_created',
        ];
    }

    /**
     * Whether this exact object has already been written by an earlier delivery.
     *
     * @param  array<string, mixed>  $payload
     */
    private function alreadyRecorded(string $event, array $payload): bool
    {
        $model = $this->recordedBy()[$event] ?? null;

        if ($model === null || ! is_subclass_of($model, Model::class)) {
            return false;
        }

        $id = data_get($payload, 'data.id');

        if (! is_string($id) && ! is_int($id)) {
            return false;
        }

        return $model::query()->where('lemon_squeezy_id', (string) $id)->exists();
    }

    /**
     * Whether the payload fails to name an account this application still holds.
     *
     * Both halves are checked because they fail differently and only one of them
     * fails tidily: absent custom data raises an exception the controller turns
     * into a 400, while custom data pointing at a deleted account raises an
     * `Error` that escapes it entirely.
     *
     * @param  array<string, mixed>  $payload
     */
    private function namesNoReachableBillable(string $event, array $payload): bool
    {
        if (! in_array($event, $this->requiringBillable(), true)) {
            return false;
        }

        $type = data_get($payload, 'meta.custom_data.billable_type');
        $id = data_get($payload, 'meta.custom_data.billable_id');

        if (! is_string($type) || $type === '' || (! is_string($id) && ! is_int($id))) {
            return true;
        }

        return ! $this->billableExists($type, $id);
    }

    /**
     * Whether the named billable is still there.
     *
     * Resolved through the morph map rather than by treating the value as a
     * class name, because that is how the value was written — `getMorphClass()`
     * answers with the alias whenever one is registered — and because an
     * authenticated payload is still the wrong place to be handed an arbitrary
     * class to instantiate.
     */
    private function billableExists(string $type, int|string $id): bool
    {
        $class = Relation::getMorphedModel($type) ?? $type;

        if (! is_subclass_of($class, Model::class)) {
            return false;
        }

        return $class::query()->whereKey($id)->exists();
    }
}
