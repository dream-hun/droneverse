<?php

declare(strict_types=1);

use App\Models\Payment;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    $this->pilot = User::factory()->create();
});

test('every order Kelviq holds is recorded, page after page', function (): void {
    fakeKelviq(responses: ['sandboxapi.kelviq.com/api/v1/orders/*' => Http::sequence()
        ->push([
            'next' => 'https://sandboxapi.kelviq.com/api/v1/orders/?page=2',
            'results' => [kelviqApiOrder('ORD-1', $this->pilot->uuid)],
        ])
        ->push([
            'next' => null,
            'results' => [kelviqApiOrder('ORD-2', $this->pilot->uuid, [
                'status' => 'REFUNDED',
                'billingType' => 'ONE_TIME',
                'isRenewal' => true,
                'subscription' => [],
                'plan' => ['identifier' => 'lifetime'],
            ])],
        ]),
    ]);

    expect(Artisan::call('kelviq:sync-payments'))->toBe(Command::SUCCESS)
        ->and(Artisan::output())
        ->toContain('Recorded 2 Kelviq orders.')
        ->not->toContain('Skipped');

    $first = Payment::query()->where('kelviq_order_id', 'ORD-1')->sole();
    $second = Payment::query()->where('kelviq_order_id', 'ORD-2')->sole();

    expect($first)
        ->user_id->toBe($this->pilot->id)
        ->kelviq_subscription_id->toBe('sub-uuid')
        ->status->toBe('COMPLETE')
        ->billing_type->toBe('SUBSCRIPTION')
        ->is_renewal->toBeNull()
        ->plan_identifier->toBe('pro')
        ->amount_units->toBe(1200)
        ->currency->toBe('USD')
        ->and($first->paid_at?->toIso8601String())->toBe('2026-09-01T10:00:00+00:00')
        ->and($first->kelviq_updated_at)->not->toBeNull()
        ->and($second)
        ->status->toBe('REFUNDED')
        ->billing_type->toBe('ONE_TIME')
        ->is_renewal->toBeTrue()
        ->kelviq_subscription_id->toBeNull()
        ->plan_identifier->toBe('lifetime');

    Http::assertSentCount(2);
});

/**
 * What Kelviq lists now is newer than any webhook already recorded for it.
 */
test('a sync updates a payment a webhook recorded, and running it again changes nothing', function (): void {
    Payment::factory()->for($this->pilot)->create([
        'kelviq_order_id' => 'ORD-1',
        'kelviq_updated_at' => now()->subDay(),
    ]);

    fakeKelviq(responses: ['sandboxapi.kelviq.com/api/v1/orders/*' => Http::response([
        'next' => null,
        'results' => [kelviqApiOrder('ORD-1', $this->pilot->uuid, ['status' => 'PARTIAL_REFUND'])],
    ])]);

    expect(Artisan::call('kelviq:sync-payments'))->toBe(Command::SUCCESS)
        ->and(Artisan::call('kelviq:sync-payments'))->toBe(Command::SUCCESS);

    expect(Payment::query()->sole()->status)->toBe('PARTIAL_REFUND');
});

test('an order with no customer is skipped and counted', function (): void {
    fakeKelviq(responses: ['sandboxapi.kelviq.com/api/v1/orders/*' => Http::response([
        'next' => null,
        'results' => [
            kelviqApiOrder('ORD-1', $this->pilot->uuid),
            kelviqApiOrder('ORD-2', ''),
        ],
    ])]);

    expect(Artisan::call('kelviq:sync-payments'))->toBe(Command::SUCCESS)
        ->and(Artisan::output())
        ->toContain('Recorded 1 Kelviq order.')
        ->toContain('Skipped 1 with no id, customer or status.');

    expect(Payment::query()->pluck('kelviq_order_id')->all())->toBe(['ORD-1']);
});

test('a next link that never ends stops after a thousand pages', function (): void {
    fakeKelviq(responses: ['sandboxapi.kelviq.com/api/v1/orders/*' => Http::response([
        'next' => 'https://sandboxapi.kelviq.com/api/v1/orders/?page=again',
        'results' => [],
    ])]);

    expect(Artisan::call('kelviq:sync-payments'))->toBe(Command::SUCCESS);

    Http::assertSentCount(1000);
    Http::assertSent(fn (Request $request): bool => $request['page'] === 1000);
});

test('nothing is synced where Kelviq is not configured', function (): void {
    config(['kelviq.server_api_key' => null]);
    Http::preventStrayRequests();

    expect(Artisan::call('kelviq:sync-payments'))->toBe(Command::FAILURE)
        ->and(Artisan::output())->toContain('Kelviq is not configured');
});

/**
 * One entry of `GET /orders/`, shaped as Kelviq's API reference shows it.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function kelviqApiOrder(string $id, string $customerId, array $overrides = []): array
{
    return array_merge([
        'id' => $id,
        'customer' => ['customerId' => $customerId, 'name' => 'Pilot', 'email' => 'pilot@example.com'],
        'status' => 'COMPLETE',
        'paidAt' => '2026-09-01T10:00:00Z',
        'amountTotal' => '12.00',
        'amountTotalUnits' => 1200,
        'saleCurrency' => 'usd',
        'plan' => ['identifier' => 'pro', 'name' => 'Pro'],
        'billingType' => 'SUBSCRIPTION',
        'subscription' => ['id' => 'sub-uuid', 'status' => 'active'],
        'invoiceId' => null,
    ], $overrides);
}
