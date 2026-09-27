<?php

declare(strict_types=1);

namespace App\Http\Integrations;

use App\Enums\KelviqEnvironment;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use JsonException;
use RuntimeException;

/**
 * Every call this application makes to Kelviq, in one place.
 *
 * Kelviq publishes SDKs for Node and Python and none for PHP, so this is a
 * port of the parts of `@kelviq/node-sdk` (2.9.0) the application uses: the
 * same endpoints, the same request bodies — snake_cased, as the SDK sends them
 * — and the same response shapes, which Kelviq answers in camelCase. Where the
 * published docs are silent, the SDK's own type definitions were the reference.
 *
 * A class rather than a set of Actions because none of these methods decides
 * anything: they are the transport, and the decisions — which plan a buyer may
 * check out, what a failure should say to a pilot, what an entitlement read
 * falls back to — live in the Actions and queries that call them.
 *
 * Failures are exceptions, always. A 4xx means the request was wrong and a 5xx
 * means Kelviq is unwell, and callers that can do something sensible about one
 * of them — a portal asked for a customer Kelviq has never seen — catch
 * RequestException and read its status.
 */
final readonly class Kelviq
{
    /** Signatures older or newer than this, in seconds, are refused. */
    private const int WEBHOOK_TOLERANCE = 300;

    /**
     * Whether this environment can talk to Kelviq at all.
     *
     * False is the normal state in tests and on a fresh checkout, and it is
     * what keeps the pricing page's upgrade buttons disabled and every
     * entitlement read answering "no" without a network call.
     */
    public function configured(): bool
    {
        return $this->apiKey() !== null;
    }

    /**
     * @throws InvalidArgumentException
     */
    public function environment(): KelviqEnvironment
    {
        return KelviqEnvironment::fromEnv(config('kelviq.environment'));
    }

    /**
     * `customers.update()`, or `customers.create()` for a customer Kelviq has
     * never seen: make its record carry this name and email address.
     *
     * The checkout payload has no field for either. A checkout names a
     * customer by id, and Kelviq's hosted page reads the name and email from
     * that customer's record. A record Kelviq creates for itself when a checkout
     * names an unknown id has the id as its name and no email at all.
     *
     * @throws ConnectionException|RequestException|RuntimeException
     */
    public function syncCustomer(string $customerId, string $name, string $email): void
    {
        $response = $this->request()->patch(sprintf('/customers/%s/', rawurlencode($customerId)), [
            'name' => $name,
            'email' => $email,
        ]);

        if ($response->notFound()) {
            $response = $this->request()->post('/customers/', [
                'customer_id' => $customerId,
                'name' => $name,
                'email' => $email,
            ]);
        }

        $response->throw();
    }

    /**
     * `checkout.createSession()`: mint a hosted checkout.
     *
     * `$payload` is sent as the body, so it speaks the API's vocabulary —
     * `plan_identifier`, `charge_period`, `customer_id`, `success_url`.
     *
     * @param  array<string, mixed>  $payload
     * @return array{checkoutSessionId: string, checkoutUrl: string}
     *
     * @throws ConnectionException|RequestException|RuntimeException
     */
    public function createCheckoutSession(array $payload): array
    {
        $session = $this->request()->post('/checkout/', $payload)->throw()->json();

        $url = is_array($session) ? ($session['checkoutUrl'] ?? null) : null;
        $id = is_array($session) ? ($session['checkoutSessionId'] ?? null) : null;

        throw_unless(is_string($url) && $url !== '' && is_string($id), RuntimeException::class, 'Kelviq returned no checkout URL.');

        return ['checkoutSessionId' => $id, 'checkoutUrl' => $url];
    }

    /**
     * `portal.createSession()`: a signed session in the customer portal.
     *
     * Kelviq answers 400 for a customer it has never seen, and for one it holds
     * without an email address. Both are "no billing account yet" to a caller,
     * and it is the caller's job to say so rather than let it become a 500.
     *
     * @return array{token: string, email: string|null, customerPortalUrl: string}
     *
     * @throws ConnectionException|RequestException|RuntimeException
     */
    public function createPortalSession(string $customerId): array
    {
        $session = $this->request()->post('/portal/session/', ['customer_id' => $customerId])->throw()->json();

        $token = is_array($session) ? ($session['token'] ?? null) : null;
        $url = is_array($session) ? ($session['customerPortalUrl'] ?? null) : null;
        $email = is_array($session) ? ($session['email'] ?? null) : null;

        throw_unless(is_string($token) && $token !== '' && is_string($url) && $url !== '', RuntimeException::class, 'Kelviq returned no portal session.');

        return ['token' => $token, 'email' => is_string($email) ? $email : null, 'customerPortalUrl' => $url];
    }

    /**
     * `entitlements.getRawEntitlements()`: every entitlement entry a customer
     * holds, unaggregated, from the edge API.
     *
     * One entry per feature per subscription, so a feature can appear more
     * than once; App\Queries\KelviqEntitlements does the aggregation the SDK
     * does, and the caching too.
     *
     * @return array<int, array<string, mixed>>
     *
     * @throws ConnectionException|RequestException|RuntimeException
     */
    public function entitlements(string $customerId): array
    {
        $response = $this->request($this->environment()->edgeApiUrl())
            ->get('/entitlements', ['customer_id' => $customerId])
            ->throw()
            ->json();

        return $this->listOf(is_array($response) ? ($response['entitlements'] ?? null) : null);
    }

    /**
     * `subscriptions.list()`: one customer's subscriptions, in whatever status.
     *
     * A page of up to a hundred, which is every subscription one pilot could
     * plausibly hold.
     *
     * @return array<int, array<string, mixed>>
     *
     * @throws ConnectionException|RequestException|RuntimeException
     */
    public function listSubscriptions(string $customerId): array
    {
        $response = $this->request()
            ->get('/subscriptions/', ['customer_id' => $customerId, 'page_size' => 100])
            ->throw()
            ->json();

        return $this->listOf(is_array($response) ? ($response['results'] ?? null) : null);
    }

    /**
     * `GET /orders/`: one page of every order the organisation holds, for
     * every customer, a hundred at a time.
     *
     * The SDK does not wrap this endpoint, so the shape is the API
     * reference's: camelCased orders under `results`, and `next` set while
     * there is another page to ask for. Kelviq never lists an order still in
     * PENDING.
     *
     * @return array{orders: array<int, array<string, mixed>>, hasMore: bool}
     *
     * @throws ConnectionException|RequestException|RuntimeException
     */
    public function listOrders(int $page): array
    {
        $response = $this->request()
            ->get('/orders/', ['page' => $page, 'page_size' => 100])
            ->throw()
            ->json();

        return [
            'orders' => $this->listOf(is_array($response) ? ($response['results'] ?? null) : null),
            'hasMore' => is_array($response) && is_string($response['next'] ?? null) && $response['next'] !== '',
        ];
    }

    /**
     * `validateEvent()`: prove a webhook body came from Kelviq, and decode it.
     *
     * The signature is a hex HMAC-SHA256 under the endpoint's signing secret of
     * `{webhook-id}.{webhook-timestamp}.{raw body}`, sent as `v1,{digest}`. The
     * body is hashed exactly as it arrived, before anything parses it.
     *
     * One addition to the SDK, which Kelviq's webhook guide asks for and the
     * SDK does not do: a timestamp more than five minutes from now is refused,
     * so a captured delivery cannot be replayed later.
     *
     * @param  array<string, mixed>  $headers  header names in any case, values as strings or lists of strings
     * @return array<string, mixed>
     *
     * @throws WebhookVerificationError
     */
    public function validateEvent(string $payload, array $headers, string $secret): array
    {
        throw_if($secret === '', WebhookVerificationError::class, 'Webhook signing secret is not configured.');

        $id = $this->header($headers, 'webhook-id');
        $timestamp = $this->header($headers, 'webhook-timestamp');
        $signature = $this->header($headers, 'webhook-signature');

        throw_if($id === null || $timestamp === null || $signature === null, WebhookVerificationError::class, 'Missing required webhook headers.');

        throw_unless(str_starts_with($signature, 'v1,'), WebhookVerificationError::class, 'Invalid signature format.');

        throw_if(
            ! ctype_digit($timestamp) || abs(now()->getTimestamp() - (int) $timestamp) > self::WEBHOOK_TOLERANCE,
            WebhookVerificationError::class,
            'Webhook timestamp is outside the tolerance.',
        );

        $expected = hash_hmac('sha256', sprintf('%s.%s.%s', $id, $timestamp, $payload), $secret);

        throw_unless(hash_equals($expected, mb_substr($signature, 3)), WebhookVerificationError::class, 'Webhook signature verification failed.');

        try {
            $event = json_decode($payload, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new WebhookVerificationError('Webhook body is not JSON.');
        }

        throw_unless(is_array($event) && ! array_is_list($event), WebhookVerificationError::class, 'Webhook body is not an event.');

        /** @var array<string, mixed> $event */
        return $event;
    }

    /**
     * @throws InvalidArgumentException|RuntimeException
     */
    private function request(?string $baseUrl = null): PendingRequest
    {
        $apiKey = $this->apiKey();

        /*
         * Refused here rather than sent with an empty header, so an environment
         * that has never configured Kelviq fails with a sentence naming the
         * problem instead of with a 401 from a third party.
         */
        throw_if($apiKey === null, RuntimeException::class, 'Kelviq is not configured: set KELVIQ_SERVER_API_KEY.');

        $timeout = config('kelviq.timeout');

        return Http::baseUrl($baseUrl ?? $this->environment()->apiUrl())
            ->withToken($apiKey)
            ->timeout(is_int($timeout) && $timeout > 0 ? $timeout : 10)
            ->acceptJson()
            ->asJson();
    }

    private function apiKey(): ?string
    {
        $apiKey = config('kelviq.server_api_key');

        return is_string($apiKey) && $apiKey !== '' ? $apiKey : null;
    }

    /**
     * @param  array<string, mixed>  $headers
     */
    private function header(array $headers, string $name): ?string
    {
        foreach ($headers as $key => $value) {
            if (mb_strtolower($key) !== $name) {
                continue;
            }

            $value = is_array($value) ? ($value[0] ?? null) : $value;

            return is_string($value) && $value !== '' ? $value : null;
        }

        return null;
    }

    /**
     * The objects in a response list, with anything that is not one dropped.
     *
     * @return array<int, array<string, mixed>>
     */
    private function listOf(mixed $items): array
    {
        if (! is_array($items)) {
            return [];
        }

        /** @var array<int, array<string, mixed>> $objects */
        $objects = array_values(array_filter($items, is_array(...)));

        return $objects;
    }
}
