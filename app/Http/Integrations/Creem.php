<?php

declare(strict_types=1);

namespace App\Http\Integrations;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Every call this application makes to Creem, in one place.
 *
 * Creem publishes no PHP SDK — the official clients are TypeScript, and the
 * REST API behind them is small enough that wrapping it costs less than
 * adopting a language runtime to reach it. A handful of endpoints,
 * all of them authenticated by one header.
 *
 * A class rather than a set of Actions because none of these methods decides
 * anything: they are the transport, and the decisions — which product a buyer
 * is charged for, whether a subscription may be moved, what a failure should
 * say to a pilot — live in the Actions that call them. That split is what lets
 * every one of those Actions be read without knowing an HTTP verb.
 *
 * Failures are exceptions, always. A 4xx from Creem means the request was
 * wrong and a 5xx means Creem is unwell, and neither is something a caller can
 * paper over with a null: a checkout that silently did not open, or a plan
 * change that silently did not happen, is worse than an error message. Callers
 * reached from a request catch Throwable and turn it into something a pilot can
 * read; see App\Http\Controllers\CheckoutController.
 */
final readonly class Creem
{
    /**
     * Whether this environment can talk to Creem at all.
     *
     * The API key is the whole of it — Creem needs no store identifier and no
     * publishable key, and the host is derived from the key's own prefix. False
     * is the normal state locally and in tests, and it is what the pricing page
     * reads to keep its upgrade buttons disabled.
     */
    public function configured(): bool
    {
        return $this->apiKey() !== null;
    }

    /**
     * Mint a checkout session and return everything Creem says about it.
     *
     * `$payload` is passed through as the request body, so it speaks Creem's
     * snake_case rather than this application's: `product_id`, `success_url`,
     * `customer`, `metadata`, `units`. The response carries `checkout_url`,
     * which is a complete standalone payment page as well as the thing the
     * embed frames.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     *
     * @throws RequestException|RuntimeException
     */
    public function createCheckout(array $payload): array
    {
        return $this->post('/checkouts', $payload);
    }

    /**
     * A magic link into the customer portal, scoped to one customer.
     *
     * Where a pilot changes the card on file, reads their invoices and requests
     * support from Creem directly. Minted per request and short-lived, so it is
     * redirected to rather than stored.
     *
     * @throws RequestException|RuntimeException
     */
    public function customerPortalUrl(string $customerId): string
    {
        $response = $this->post('/customers/billing', ['customer_id' => $customerId]);

        $link = $response['customer_portal_link'] ?? null;

        throw_unless(is_string($link) && $link !== '', RuntimeException::class, 'Creem returned no customer portal link.');

        return $link;
    }

    /**
     * End a subscription.
     *
     * `scheduled` keeps the pilot on the plan until the period they have paid
     * for runs out, leaving the subscription in Creem's `scheduled_cancel`
     * status; `immediate` cuts access off on the spot and is not what any
     * button in this application does.
     *
     * @return array<string, mixed>
     *
     * @throws RequestException|RuntimeException
     */
    public function cancelSubscription(string $subscriptionId, string $mode = 'scheduled'): array
    {
        return $this->post('/subscriptions/'.$subscriptionId.'/cancel', ['mode' => $mode]);
    }

    /**
     * Call off a scheduled cancellation, or restart a paused subscription.
     *
     * Creem refuses this for a subscription in any other status, which is what
     * makes App\Actions\ResumeSubscription check before it calls.
     *
     * @return array<string, mixed>
     *
     * @throws RequestException|RuntimeException
     */
    public function resumeSubscription(string $subscriptionId): array
    {
        return $this->post('/subscriptions/'.$subscriptionId.'/resume', []);
    }

    /**
     * Move a subscription onto a different product.
     *
     * Both directions: Creem calls the endpoint "upgrade" and uses it for
     * downgrades too, since which one it is depends only on the prices either
     * side. `$updateBehavior` decides what happens to the money — see
     * App\Actions\SwapSubscription, which is the only caller and explains the
     * choice it makes.
     *
     * @return array<string, mixed>
     *
     * @throws RequestException|RuntimeException
     */
    public function upgradeSubscription(string $subscriptionId, string $productId, string $updateBehavior): array
    {
        return $this->post('/subscriptions/'.$subscriptionId.'/upgrade', [
            'product_id' => $productId,
            'update_behavior' => $updateBehavior,
        ]);
    }

    /**
     * A subscription as Creem currently holds it.
     *
     * The same object every `subscription.*` webhook carries, which is what
     * lets App\Actions\ReconcileCreemBilling feed it straight into
     * App\Actions\SyncCreemSubscription when a webhook never arrived.
     *
     * @return array<string, mixed>
     *
     * @throws RequestException|RuntimeException
     */
    public function retrieveSubscription(string $subscriptionId): array
    {
        return $this->get('/subscriptions', ['subscription_id' => $subscriptionId]);
    }

    /**
     * One page of a customer's transactions, newest first.
     *
     * The only place a renewal's payment can be read from: `subscription.paid`
     * names the transaction but does not carry it.
     *
     * @return array<string, mixed>
     *
     * @throws RequestException|RuntimeException
     */
    public function searchTransactions(string $customerId, int $page = 1, int $pageSize = 50): array
    {
        return $this->get('/transactions/search', [
            'customer_id' => $customerId,
            'page_number' => $page,
            'page_size' => $pageSize,
        ]);
    }

    /**
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     *
     * @throws RequestException|RuntimeException
     */
    private function get(string $path, array $query): array
    {
        /** @var array<string, mixed> $body */
        $body = $this->request()->get($path, $query)->throw()->json();

        return $body;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     *
     * @throws RequestException|RuntimeException
     */
    private function post(string $path, array $payload): array
    {
        /** @var array<string, mixed> $body */
        $body = $this->request()->post($path, $payload)->throw()->json();

        return $body;
    }

    /**
     * @throws RuntimeException
     */
    private function request(): PendingRequest
    {
        $apiKey = $this->apiKey();

        /*
         * Refused here rather than sent with an empty header, so an environment
         * that has never configured Creem fails with a sentence naming the
         * problem instead of with a 403 from a third party.
         */
        throw_if($apiKey === null, RuntimeException::class, 'Creem is not configured: set CREEM_API_KEY.');

        return Http::baseUrl($this->baseUrl())
            ->withHeader('x-api-key', $apiKey)
            ->timeout($this->timeout())
            ->acceptJson()
            ->asJson();
    }

    private function apiKey(): ?string
    {
        $apiKey = config('creem.api_key');

        return is_string($apiKey) && $apiKey !== '' ? $apiKey : null;
    }

    /**
     * Which of Creem's two entirely separate worlds this environment sells in.
     *
     * Test mode is a different host with its own database, its own products and
     * its own keys, and the two are not interchangeable — a live key sent to
     * the test host is simply an unknown key. So the host is derived from the
     * key rather than configured beside it: the key's own prefix already says
     * which world it belongs to, and a second value saying the same thing is a
     * second value that can disagree.
     *
     * `creem.api_url` overrides it for the environment that genuinely needs to
     * point somewhere else, and is unset everywhere else.
     */
    private function baseUrl(): string
    {
        $configured = config('creem.api_url');

        if (is_string($configured) && $configured !== '') {
            return mb_rtrim($configured, '/');
        }

        return str_starts_with((string) $this->apiKey(), 'creem_test_')
            ? 'https://test-api.creem.io/v1'
            : 'https://api.creem.io/v1';
    }

    private function timeout(): int
    {
        $timeout = config('creem.timeout');

        return is_int($timeout) && $timeout > 0 ? $timeout : 15;
    }
}
