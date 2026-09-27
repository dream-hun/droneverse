<?php

declare(strict_types=1);

use App\Models\Payment;
use App\Models\User;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Support\Facades\Cache;
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

test('an order with no modification time of its own is dated by the event', function (): void {
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
