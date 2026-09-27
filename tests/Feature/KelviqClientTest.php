<?php

declare(strict_types=1);

use App\Enums\KelviqEnvironment;
use App\Http\Integrations\Kelviq;
use App\Http\Integrations\WebhookVerificationError;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;

test('an unset or blank KELVIQ_ENV is the sandbox', function (mixed $value): void {
    expect(KelviqEnvironment::fromEnv($value))->toBe(KelviqEnvironment::Sandbox);
})->with([null, '', '   ', 42]);

test('KELVIQ_ENV names one of the two worlds', function (): void {
    expect(KelviqEnvironment::fromEnv('production'))->toBe(KelviqEnvironment::Production)
        ->and(KelviqEnvironment::fromEnv(' sandbox '))->toBe(KelviqEnvironment::Sandbox);
});

test('a KELVIQ_ENV that names neither world is refused rather than guessed at', function (): void {
    KelviqEnvironment::fromEnv('prod');
})->throws(InvalidArgumentException::class, 'Invalid KELVIQ_ENV value "prod"');

test('each world has its own api and edge hosts', function (): void {
    expect(KelviqEnvironment::Sandbox->apiUrl())->toBe('https://sandboxapi.kelviq.com/api/v1')
        ->and(KelviqEnvironment::Sandbox->edgeApiUrl())->toBe('https://edge.sandboxapi.kelviq.com/api/v1')
        ->and(KelviqEnvironment::Production->apiUrl())->toBe('https://api.kelviq.com/api/v1')
        ->and(KelviqEnvironment::Production->edgeApiUrl())->toBe('https://edge.api.kelviq.com/api/v1');
});

test('kelviq is configured by its server key alone', function (): void {
    $kelviq = resolve(Kelviq::class);

    expect($kelviq->configured())->toBeFalse();

    config(['kelviq.server_api_key' => 'kq_sandbox_test_key']);

    expect($kelviq->configured())->toBeTrue();
});

test('an unconfigured environment refuses to call kelviq at all', function (): void {
    Http::preventStrayRequests();

    resolve(Kelviq::class)->createPortalSession('pilot-uuid');
})->throws(RuntimeException::class, 'Kelviq is not configured: set KELVIQ_SERVER_API_KEY.');

test('a checkout session is minted with the bearer key and the sdk body', function (): void {
    fakeKelviq(responses: [
        'sandboxapi.kelviq.com/api/v1/checkout/' => Http::response([
            'checkoutSessionId' => 'cs_123',
            'checkoutUrl' => 'https://kelviq.com/checkout/cs_123/',
        ], 201),
    ]);

    $session = resolve(Kelviq::class)->createCheckoutSession([
        'plan_identifier' => 'pro',
        'charge_period' => 'MONTHLY',
    ]);

    expect($session)->toBe(['checkoutSessionId' => 'cs_123', 'checkoutUrl' => 'https://kelviq.com/checkout/cs_123/']);

    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://sandboxapi.kelviq.com/api/v1/checkout/'
        && $request->method() === 'POST'
        && $request->hasHeader('Authorization', 'Bearer kq_sandbox_test_key')
        && $request['plan_identifier'] === 'pro');
});

test('a customer kelviq holds is updated in place', function (): void {
    fakeKelviq();

    resolve(Kelviq::class)->syncCustomer('pilot-uuid', 'Ada Pilot', 'pilot@example.com');

    Http::assertSentCount(1);
    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://sandboxapi.kelviq.com/api/v1/customers/pilot-uuid/'
        && $request->method() === 'PATCH'
        && $request->hasHeader('Authorization', 'Bearer kq_sandbox_test_key')
        && $request->data() === ['name' => 'Ada Pilot', 'email' => 'pilot@example.com']);
});

test('a customer kelviq has never seen is created', function (): void {
    fakeKelviq(responses: ['https://sandboxapi.kelviq.com/api/v1/customers/*' => fn (Request $request): PromiseInterface => $request->method() === 'PATCH'
        ? Http::response(['detail' => 'Not found.'], 404)
        : Http::response(['customerId' => 'pilot-uuid'], 201)]);

    resolve(Kelviq::class)->syncCustomer('pilot-uuid', 'Ada Pilot', 'pilot@example.com');

    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://sandboxapi.kelviq.com/api/v1/customers/'
        && $request->method() === 'POST'
        && $request->data() === ['customer_id' => 'pilot-uuid', 'name' => 'Ada Pilot', 'email' => 'pilot@example.com']);
});

test('a customer kelviq refuses is an error', function (): void {
    fakeKelviq(responses: ['https://sandboxapi.kelviq.com/api/v1/customers/*' => Http::response(['email' => ['Enter a valid email address.']], 400)]);

    resolve(Kelviq::class)->syncCustomer('pilot-uuid', 'Ada Pilot', 'not-an-email');
})->throws(RequestException::class);

test('production keys are sent to the production host', function (): void {
    config(['kelviq.server_api_key' => 'kq_live_key', 'kelviq.environment' => 'production', 'kelviq.timeout' => 'soon']);
    Http::preventStrayRequests();
    Http::fake(['api.kelviq.com/api/v1/portal/session/' => Http::response([
        'token' => 'cpt-1',
        'email' => null,
        'customerPortalUrl' => 'https://www.kelviq.com/portal/droneverse/',
    ])]);

    expect(resolve(Kelviq::class)->createPortalSession('pilot-uuid'))->toBe([
        'token' => 'cpt-1',
        'email' => null,
        'customerPortalUrl' => 'https://www.kelviq.com/portal/droneverse/',
    ]);

    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://api.kelviq.com/api/v1/portal/session/'
        && $request['customer_id'] === 'pilot-uuid');
});

test('a checkout answer without a url is an error rather than a blank redirect', function (): void {
    fakeKelviq(responses: ['sandboxapi.kelviq.com/api/v1/checkout/' => Http::response(['checkoutSessionId' => 'cs_123'], 201)]);

    resolve(Kelviq::class)->createCheckoutSession([]);
})->throws(RuntimeException::class, 'Kelviq returned no checkout URL.');

test('a portal answer without a token is an error', function (): void {
    fakeKelviq(responses: ['sandboxapi.kelviq.com/api/v1/portal/session/' => Http::response('not json')]);

    resolve(Kelviq::class)->createPortalSession('pilot-uuid');
})->throws(RuntimeException::class, 'Kelviq returned no portal session.');

test('a kelviq error status is thrown for the caller to read', function (): void {
    fakeKelviq(responses: ['sandboxapi.kelviq.com/api/v1/portal/session/' => Http::response(['customerId' => ['Unknown customer.']], 400)]);

    resolve(Kelviq::class)->createPortalSession('pilot-uuid');
})->throws(RequestException::class);

test('entitlements are read raw from the edge api', function (): void {
    fakeKelviq(['pilot-uuid' => ['full-catalog']]);

    expect(resolve(Kelviq::class)->entitlements('pilot-uuid'))->toBe([
        ['featureId' => 'full-catalog', 'featureType' => 'BOOLEAN', 'hasAccess' => true],
    ]);

    Http::assertSent(fn (Request $request): bool => str_starts_with($request->url(), 'https://edge.sandboxapi.kelviq.com/api/v1/entitlements')
        && $request['customer_id'] === 'pilot-uuid');
});

test('anything in a response list that is not an object is dropped', function (): void {
    fakeKelviq(responses: [
        'edge.sandboxapi.kelviq.com/api/v1/entitlements*' => Http::response(['entitlements' => [['featureId' => 'a'], 'junk', 7]]),
        'sandboxapi.kelviq.com/api/v1/subscriptions/*' => Http::response(['results' => 'not a list']),
    ]);

    $kelviq = resolve(Kelviq::class);

    expect($kelviq->entitlements('pilot-uuid'))->toBe([['featureId' => 'a']])
        ->and($kelviq->listSubscriptions('pilot-uuid'))->toBe([]);
});

test("a customer's subscriptions are listed a page of a hundred at a time", function (): void {
    fakeKelviq(responses: ['sandboxapi.kelviq.com/api/v1/subscriptions/*' => Http::response([
        'count' => 1,
        'results' => [['id' => 'sub_1', 'status' => 'active', 'endDate' => null]],
    ])]);

    expect(resolve(Kelviq::class)->listSubscriptions('pilot-uuid'))->toBe([['id' => 'sub_1', 'status' => 'active', 'endDate' => null]]);

    Http::assertSent(fn (Request $request): bool => $request['customer_id'] === 'pilot-uuid' && $request['page_size'] === 100);
});

describe('validateEvent', function (): void {
    beforeEach(function (): void {
        $this->body = (string) json_encode(['id' => 'evt_1', 'type' => 'subscription.created']);
    });

    test('a signed body is decoded', function (): void {
        $headers = array_change_key_case(kelviqWebhookHeaders($this->body, 'secret'), CASE_UPPER);

        expect(resolve(Kelviq::class)->validateEvent($this->body, $headers, 'secret'))
            ->toBe(['id' => 'evt_1', 'type' => 'subscription.created']);
    });

    test('headers arrive as lists from a request', function (): void {
        $headers = array_map(static fn (string $value): array => [$value], kelviqWebhookHeaders($this->body, 'secret'));

        expect(resolve(Kelviq::class)->validateEvent($this->body, $headers, 'secret'))->toHaveKey('id', 'evt_1');
    });

    test('nothing verifies without a secret', function (): void {
        resolve(Kelviq::class)->validateEvent($this->body, kelviqWebhookHeaders($this->body, ''), '');
    })->throws(WebhookVerificationError::class, 'Webhook signing secret is not configured.');

    test('every signature header is required', function (): void {
        $headers = kelviqWebhookHeaders($this->body, 'secret');
        $headers['webhook-id'] = '';

        resolve(Kelviq::class)->validateEvent($this->body, $headers, 'secret');
    })->throws(WebhookVerificationError::class, 'Missing required webhook headers.');

    test('a signature in another scheme is refused', function (): void {
        $headers = kelviqWebhookHeaders($this->body, 'secret');
        $headers['webhook-signature'] = 'v2,'.mb_substr($headers['webhook-signature'], 3);

        resolve(Kelviq::class)->validateEvent($this->body, $headers, 'secret');
    })->throws(WebhookVerificationError::class, 'Invalid signature format.');

    test('a delivery signed more than five minutes ago is refused', function (): void {
        $headers = kelviqWebhookHeaders($this->body, 'secret', now()->subMinutes(6)->getTimestamp());

        resolve(Kelviq::class)->validateEvent($this->body, $headers, 'secret');
    })->throws(WebhookVerificationError::class, 'Webhook timestamp is outside the tolerance.');

    test('a timestamp that is not a number is refused', function (): void {
        $headers = kelviqWebhookHeaders($this->body, 'secret');
        $headers['webhook-timestamp'] = 'yesterday';

        resolve(Kelviq::class)->validateEvent($this->body, $headers, 'secret');
    })->throws(WebhookVerificationError::class, 'Webhook timestamp is outside the tolerance.');

    test('a body signed under another secret is refused', function (): void {
        resolve(Kelviq::class)->validateEvent($this->body, kelviqWebhookHeaders($this->body, 'other'), 'secret');
    })->throws(WebhookVerificationError::class, 'Webhook signature verification failed.');

    test('a body changed after signing is refused', function (): void {
        resolve(Kelviq::class)->validateEvent($this->body.' ', kelviqWebhookHeaders($this->body, 'secret'), 'secret');
    })->throws(WebhookVerificationError::class, 'Webhook signature verification failed.');

    test('a signed body that is not json is refused', function (): void {
        resolve(Kelviq::class)->validateEvent('not json', kelviqWebhookHeaders('not json', 'secret'), 'secret');
    })->throws(WebhookVerificationError::class, 'Webhook body is not JSON.');

    test('a signed body that is not an event object is refused', function (): void {
        resolve(Kelviq::class)->validateEvent('[1,2]', kelviqWebhookHeaders('[1,2]', 'secret'), 'secret');
    })->throws(WebhookVerificationError::class, 'Webhook body is not an event.');
});
