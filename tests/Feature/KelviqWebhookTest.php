<?php

declare(strict_types=1);

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
