<?php

declare(strict_types=1);

use App\Models\Payment;
use App\Models\User;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

beforeEach(function (): void {
    $this->pilot = User::factory()->create();
    fakeKelviq([$this->pilot->uuid => ['full-catalog']]);
});

test('a delivery with no signing secret configured is refused', function (): void {
    config(['kelviq.webhook_secret' => null]);

    postKelviqWebhook($this, kelviqEvent('subscription.created', $this->pilot->uuid))->assertForbidden();
});

test('a delivery signed under another secret is refused', function (): void {
    $body = (string) json_encode(kelviqEvent('subscription.created', $this->pilot->uuid));

    $this->call('POST', route('kelviq.webhook'), [], [], [], headersToServer(kelviqWebhookHeaders($body, 'not-the-secret')), $body)
        ->assertForbidden();
});

test('an unsigned delivery is refused', function (): void {
    $this->postJson(route('kelviq.webhook'), kelviqEvent('subscription.created', $this->pilot->uuid))
        ->assertForbidden();
});

/**
 * Posted as a server, with no session and no CSRF token: the route is outside
 * the `web` group, so the signature alone is what admits it.
 */
test('every event about a customer drops their cached entitlements', function (string $type): void {
    Cache::put('kelviq:entitlements:'.$this->pilot->uuid, [], 60);

    postKelviqWebhook($this, kelviqEvent($type, $this->pilot->uuid))->assertOk();

    expect(Cache::has('kelviq:entitlements:'.$this->pilot->uuid))->toBeFalse();
})->with([
    'checkout.completed',
    'invoice.payment_failed',
    'subscription.created',
    'subscription.updated',
    'subscription.plan_changed',
    'subscription.cancelled',
    'order.created',
    'order.updated',
    'order.refunded',
]);

test('an event this application does not handle is received and ignored', function (): void {
    Cache::put('kelviq:entitlements:'.$this->pilot->uuid, [], 60);

    postKelviqWebhook($this, kelviqEvent('invoice.paid', $this->pilot->uuid))->assertOk();

    expect(Cache::has('kelviq:entitlements:'.$this->pilot->uuid))->toBeTrue();
});

test('an event naming no customer is received and ignored', function (): void {
    $event = kelviqEvent('subscription.created', $this->pilot->uuid);
    unset($event['data']);

    postKelviqWebhook($this, $event)->assertOk();
});

test('a redelivered event is handled once', function (): void {
    $key = 'kelviq:entitlements:'.$this->pilot->uuid;
    $event = kelviqEvent('subscription.created', $this->pilot->uuid);

    Cache::put($key, [], 60);
    postKelviqWebhook($this, $event)->assertOk();

    expect(Cache::has($key))->toBeFalse();

    Cache::put($key, [], 60);
    postKelviqWebhook($this, $event)->assertOk();

    expect(Cache::has($key))->toBeTrue('The duplicate should not have been handled.');
});

test('an event with no id is received and not handled', function (): void {
    $key = 'kelviq:entitlements:'.$this->pilot->uuid;
    Cache::put($key, [], 60);

    $event = kelviqEvent('subscription.created', $this->pilot->uuid);
    unset($event['id']);

    postKelviqWebhook($this, $event)->assertOk();

    expect(Cache::has($key))->toBeTrue();
});

/**
 * A delivery that fails part-way releases its claim on the event id, so
 * Kelviq's retry is handled rather than skipped as a duplicate.
 */
test('a delivery that fails is handled again when kelviq retries it', function (): void {
    $store = new class extends ArrayStore
    {
        public int $failures = 1;

        public function forget($key)
        {
            if (str_starts_with((string) $key, 'kelviq:entitlements:') && $this->failures > 0) {
                $this->failures--;

                throw new RuntimeException('cache went away');
            }

            return parent::forget($key);
        }
    };

    Cache::extend('flaky', fn (): Repository => Cache::repository($store));
    config(['cache.stores.flaky' => ['driver' => 'flaky'], 'cache.default' => 'flaky']);

    $event = kelviqEvent('subscription.created', $this->pilot->uuid);

    $this->withoutExceptionHandling();

    expect(fn (): TestResponse => postKelviqWebhook($this, $event))->toThrow(RuntimeException::class, 'cache went away');

    Cache::put('kelviq:entitlements:'.$this->pilot->uuid, [], 60);
    postKelviqWebhook($this, $event)->assertOk();

    expect(Cache::has('kelviq:entitlements:'.$this->pilot->uuid))->toBeFalse('The retry should have been handled.');
});

test('an order is recorded as a payment for the pilot who made it', function (): void {
    postKelviqWebhook($this, kelviqOrderEvent('order.created', $this->pilot->uuid))->assertOk();

    $payment = Payment::query()->sole();

    expect($payment->kelviq_order_id)->toBe('ORD-20260927100000-AB3K9')
        ->and($payment->user_id)->toBe($this->pilot->id)
        ->and($payment->kelviq_customer_id)->toBe($this->pilot->uuid)
        ->and($payment->kelviq_subscription_id)->toBe('sub-uuid')
        ->and($payment->status)->toBe('COMPLETE')
        ->and($payment->billing_type)->toBe('SUBSCRIPTION')
        ->and($payment->is_renewal)->toBeFalse()
        ->and($payment->plan_identifier)->toBe('pro')
        ->and($payment->amount_units)->toBe(1200)
        ->and($payment->currency)->toBe('USD')
        ->and($payment->paid_at?->toIso8601String())->toBe('2026-09-27T10:00:00+00:00')
        ->and($payment->kelviq_updated_at?->toIso8601String())->toBe('2026-09-27T10:00:05+00:00')
        ->and($this->pilot->payments()->count())->toBe(1);
});

test('a refund updates the payment it refunds rather than adding one', function (): void {
    postKelviqWebhook($this, kelviqOrderEvent('order.created', $this->pilot->uuid))->assertOk();
    postKelviqWebhook($this, kelviqOrderEvent('order.refunded', $this->pilot->uuid, [
        'status' => 'REFUNDED',
        'modified_on' => '2026-09-28T09:00:00Z',
    ]))->assertOk();

    expect(Payment::query()->sole()->status)->toBe('REFUNDED');
});

/**
 * Webhooks are not delivered in order, and a late description of an order
 * must not undo a newer one.
 */
test('an older description of an order does not overwrite a newer one', function (): void {
    postKelviqWebhook($this, kelviqOrderEvent('order.refunded', $this->pilot->uuid, [
        'status' => 'REFUNDED',
        'modified_on' => '2026-09-28T09:00:00Z',
    ]))->assertOk();
    postKelviqWebhook($this, kelviqOrderEvent('order.created', $this->pilot->uuid))->assertOk();

    expect(Payment::query()->sole()->status)->toBe('REFUNDED');
});

/**
 * Kelviq dates each change to the microsecond, and an order is routinely
 * created and paid for within the same second. Kept to whole seconds, the two
 * descriptions tied, and whichever was delivered last won.
 */
test('descriptions of an order from the same second are told apart', function (string $first, string $second): void {
    $descriptions = [
        'pending' => kelviqOrderEvent('order.created', $this->pilot->uuid, [
            'status' => 'PENDING',
            'paid_at' => null,
            'modified_on' => '2026-09-27T10:00:05.200000Z',
        ]),
        'paid' => kelviqOrderEvent('order.updated', $this->pilot->uuid, [
            'modified_on' => '2026-09-27T10:00:05.800000Z',
        ]),
    ];

    postKelviqWebhook($this, $descriptions[$first])->assertOk();
    postKelviqWebhook($this, $descriptions[$second])->assertOk();

    $payment = Payment::query()->sole();

    expect($payment->status)->toBe('COMPLETE')
        ->and($payment->paid_at?->toIso8601String())->toBe('2026-09-27T10:00:00+00:00')
        ->and($payment->kelviq_updated_at?->format('Y-m-d H:i:s.u'))->toBe('2026-09-27 10:00:05.800000');
})->with([
    'delivered in order' => ['pending', 'paid'],
    'delivered out of order' => ['paid', 'pending'],
]);

/**
 * An offset is part of the moment it qualifies. Stored as the wall-clock time
 * it reads as, `+02:00` came back two hours late, and a refund made after the
 * payment looked older than it and was dropped.
 */
test('a time sent with an offset is recorded as the moment it names', function (): void {
    postKelviqWebhook($this, kelviqOrderEvent('order.created', $this->pilot->uuid, [
        'paid_at' => '2026-09-27T12:00:00+02:00',
        'modified_on' => '2026-09-27T12:00:05+02:00',
    ]))->assertOk();
    postKelviqWebhook($this, kelviqOrderEvent('order.refunded', $this->pilot->uuid, [
        'status' => 'REFUNDED',
        'modified_on' => '2026-09-27T11:00:00Z',
    ]))->assertOk();

    $payment = Payment::query()->sole();

    expect($payment->status)->toBe('REFUNDED')
        ->and($payment->paid_at?->toIso8601String())->toBe('2026-09-27T10:00:00+00:00');
});

/**
 * Kelviq delivers concurrently, and the claim on the event id only stops one
 * event being handled twice: an `order.created` and the `order.updated` close
 * behind it are two events about one order. Whichever loses the race to create
 * the row has to be recorded against it rather than fail on the unique key.
 *
 * The other delivery's row is landed straight after this one looks the order
 * up, which is the window a read followed by an insert leaves open.
 */
test('a delivery that loses the race to create its order is still recorded', function (): void {
    $raced = false;

    DB::listen(function (QueryExecuted $query) use (&$raced): void {
        $sql = mb_strtolower($query->sql);

        if ($raced || ! str_starts_with($sql, 'select') || ! str_contains($sql, 'payments') || ! str_contains($sql, 'kelviq_order_id')) {
            return;
        }

        $raced = true;

        DB::table('payments')->insert([
            'kelviq_order_id' => 'ORD-20260927100000-AB3K9',
            'kelviq_customer_id' => $this->pilot->uuid,
            'status' => 'PENDING',
            'kelviq_updated_at' => '2026-09-27 10:00:05.200000',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    });

    postKelviqWebhook($this, kelviqOrderEvent('order.updated', $this->pilot->uuid, [
        'modified_on' => '2026-09-27T10:00:05.800000Z',
    ]))->assertOk();

    expect(Payment::query()->sole())
        ->status->toBe('COMPLETE')
        ->user_id->toBe($this->pilot->id);
});

test('an order with no modification time of its own is dated by the event', function (): void {
    // Kept as absent, but not silently: an unreadable time means Kelviq's format has changed.
    Log::shouldReceive('warning')
        ->once()
        ->withArgs(fn (string $message, array $context): bool => str_contains($message, 'could not be read') && ($context['value'] ?? null) === 'not a date');

    postKelviqWebhook($this, kelviqOrderEvent('order.created', $this->pilot->uuid, ['modified_on' => 'not a date']))->assertOk();

    expect(Payment::query()->sole()->kelviq_updated_at?->toIso8601String())->toBe('2026-09-27T10:00:10+00:00');
});

test('an order with no time at all is still recorded, and keeps what it had', function (): void {
    postKelviqWebhook($this, kelviqOrderEvent('order.created', $this->pilot->uuid))->assertOk();

    $event = kelviqOrderEvent('order.updated', $this->pilot->uuid, ['modified_on' => null, 'status' => 'PARTIAL_REFUND']);
    unset($event['created_at']);
    postKelviqWebhook($this, $event)->assertOk();

    $payment = Payment::query()->sole();

    expect($payment->status)->toBe('PARTIAL_REFUND')
        ->and($payment->kelviq_updated_at?->toIso8601String())->toBe('2026-09-27T10:00:05+00:00');
});

test('an order for a customer with no account is recorded without a pilot', function (): void {
    postKelviqWebhook($this, kelviqOrderEvent('order.created', 'dashboard-sale'))->assertOk();

    expect(Payment::query()->sole())
        ->user_id->toBeNull()
        ->kelviq_customer_id->toBe('dashboard-sale');
});

test('an order with fields of the wrong shape keeps only what it can read', function (): void {
    postKelviqWebhook($this, kelviqOrderEvent('order.created', $this->pilot->uuid, [
        'subscription_id' => '',
        'is_renewal' => 'no',
        'plan' => null,
        'amount_total_units' => -5,
        'currency' => 'dollars',
        'paid_at' => 12,
    ]))->assertOk();

    expect(Payment::query()->sole())
        ->kelviq_subscription_id->toBeNull()
        ->is_renewal->toBeNull()
        ->plan_identifier->toBeNull()
        ->amount_units->toBeNull()
        ->currency->toBeNull()
        ->paid_at->toBeNull();
});

test('an order with no id or status is not recorded', function (string $field, ?string $value): void {
    postKelviqWebhook($this, kelviqOrderEvent('order.created', $this->pilot->uuid, [$field => $value]))->assertOk();

    expect(Payment::query()->count())->toBe(0);
})->with([
    'no id' => ['id', null],
    'no status' => ['status', ''],
]);

test('an event that is not about an order records no payment', function (): void {
    postKelviqWebhook($this, kelviqEvent('checkout.completed', $this->pilot->uuid))->assertOk();

    expect(Payment::query()->count())->toBe(0);
});

/**
 * @return array<string, mixed>
 */
function kelviqEvent(string $type, string $customerId): array
{
    return [
        'id' => 'evt_'.fake()->uuid(),
        'type' => $type,
        'created_at' => now()->toIso8601String(),
        'data' => ['object' => ['customer' => ['id' => 'kvq-uuid', 'customer_id' => $customerId, 'email' => 'pilot@example.com']]],
    ];
}

/**
 * An `order.*` event shaped as Kelviq's webhook guide shows it.
 *
 * @param  array<string, mixed>  $overrides  merged over `data.object`
 * @return array<string, mixed>
 */
function kelviqOrderEvent(string $type, string $customerId, array $overrides = []): array
{
    return [
        'id' => 'evt_'.fake()->uuid(),
        'type' => $type,
        'created_at' => '2026-09-27T10:00:10Z',
        'data' => ['object' => array_merge([
            'id' => 'ORD-20260927100000-AB3K9',
            'object' => 'order',
            'status' => 'COMPLETE',
            'billing_type' => 'SUBSCRIPTION',
            'customer_id' => 'kvq-uuid',
            'customer' => ['id' => 'kvq-uuid', 'customer_id' => $customerId, 'email' => 'pilot@example.com'],
            'is_renewal' => false,
            'paid_at' => '2026-09-27T10:00:00Z',
            'amount_total' => '12.00',
            'amount_total_units' => 1200,
            'currency' => 'usd',
            'subscription_id' => 'sub-uuid',
            'plan' => ['name' => 'Pro', 'version' => 1, 'identifier' => 'pro'],
            'created_on' => '2026-09-27T10:00:00Z',
            'modified_on' => '2026-09-27T10:00:05Z',
        ], $overrides)],
    ];
}

/**
 * @param  array<string, mixed>  $event
 * @return TestResponse<Response>
 */
function postKelviqWebhook(TestCase $test, array $event): TestResponse
{
    $body = (string) json_encode($event);

    return $test->call('POST', route('kelviq.webhook'), [], [], [], headersToServer(kelviqWebhookHeaders($body)), $body);
}

/**
 * @param  array<string, string>  $headers
 * @return array<string, string>
 */
function headersToServer(array $headers): array
{
    $server = ['CONTENT_TYPE' => 'application/json'];

    foreach ($headers as $name => $value) {
        $server['HTTP_'.mb_strtoupper(str_replace('-', '_', $name))] = $value;
    }

    return $server;
}
