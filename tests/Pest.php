<?php

declare(strict_types=1);

use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| Every feature test gets the application test case and a migrated database.
| Both were declared file by file before the suite moved to Pest — a class
| that extended TestCase and a `use RefreshDatabase` on the line below it —
| and every one of them declared the same two things. Stated once here they
| stay stated: a new feature test file cannot forget the database and then
| pass by reading rows a neighboring test left behind.
|
| Unit tests are deliberately left out. The two files under tests/Unit do
| not agree on what they need — PlanTest wants the application and
| ScoringPolicyTest wants neither it nor the database — so each states its
| own needs rather than inheriting a default the other would have to undo.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Kelviq
|--------------------------------------------------------------------------
|
| The suite runs with no Kelviq key — phpunit.xml blanks it — so nothing
| reaches Kelviq unless a test asks. fakeKelviq() configures a sandbox key,
| refuses any request it was not told about, and answers the edge
| entitlements API from `$entitlements`, which maps a customer id (a pilot's
| uuid) to the feature identifiers Kelviq grants them. `$responses` is passed
| to Http::fake() ahead of that, for the endpoints a test is about, and wins
| over it for the same URL pattern.
|
*/

/**
 * @param  array<string, array<int, string>>  $entitlements
 * @param  array<string, mixed>  $responses
 */
function fakeKelviq(array $entitlements = [], array $responses = []): void
{
    config([
        'kelviq.server_api_key' => 'kq_sandbox_test_key',
        'kelviq.environment' => 'sandbox',
        'kelviq.webhook_secret' => 'whsec_test_secret',
    ]);

    Http::preventStrayRequests();

    Http::fake($responses + [
        'edge.sandboxapi.kelviq.com/api/v1/entitlements*' => function (Request $request) use ($entitlements): PromiseInterface {
            $customerId = $request->data()['customer_id'] ?? null;
            $granted = is_string($customerId) ? $entitlements[$customerId] ?? [] : [];

            return Http::response([
                'customerId' => $customerId,
                'entitlements' => array_map(static fn (string $featureId): array => [
                    'featureId' => $featureId,
                    'featureType' => 'BOOLEAN',
                    'hasAccess' => true,
                ], $granted),
            ]);
        },
    ]);
}

/**
 * Sign a webhook body the way Kelviq does, returning the headers to send.
 *
 * @return array<string, string>
 */
function kelviqWebhookHeaders(string $body, string $secret = 'whsec_test_secret', ?int $timestamp = null, string $id = 'msg_test'): array
{
    $timestamp ??= now()->getTimestamp();

    return [
        'webhook-id' => $id,
        'webhook-timestamp' => (string) $timestamp,
        'webhook-signature' => 'v1,'.hash_hmac('sha256', sprintf('%s.%d.%s', $id, $timestamp, $body), $secret),
    ];
}
