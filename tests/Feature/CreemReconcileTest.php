<?php

declare(strict_types=1);

use App\Enums\Plan;
use App\Enums\SubscriptionStatus;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/*
 * A renewal whose webhook never arrived is recovered by reading it back from
 * Creem: the subscription's status and dates, and the payment as an order.
 */

beforeEach(function (): void {
    config([
        'creem.api_key' => 'creem_test_key',
        'plans.prices' => ['pro' => ['monthly' => 'prod_pro_monthly']],
    ]);
});

/**
 * @return array{0: User, 1: Subscription}
 */
function reconcileSubscriber(string $status = 'active'): array
{
    $user = User::factory()->create();

    $subscription = Subscription::factory()->for($user, 'billable')->create([
        'creem_id' => 'sub_900001',
        'customer_id' => 'cust_800001',
        'product_id' => 'prod_pro_monthly',
        'status' => $status,
        'renews_at' => '2026-08-01 00:00:00',
        'current_period_start_at' => '2026-07-01 00:00:00',
        'current_period_end_at' => '2026-08-01 00:00:00',
    ]);

    Customer::factory()->for($user, 'billable')->create(['creem_id' => 'cust_800001']);

    Order::factory()->for($user, 'billable')->create([
        'creem_id' => 'ord_700001',
        'checkout_id' => 'ch_1',
        'customer_id' => 'cust_800001',
        'product_id' => 'prod_pro_monthly',
        'subscription_id' => 'sub_900001',
        'ordered_at' => '2026-07-01 00:00:05',
    ]);

    return [$user, $subscription];
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function remoteSubscription(array $overrides = []): array
{
    return [
        'id' => 'sub_900001',
        'object' => 'subscription',
        'product' => 'prod_pro_monthly',
        'customer' => 'cust_800001',
        'status' => 'active',
        'next_transaction_date' => '2026-09-01T00:00:00.000Z',
        'current_period_start_date' => '2026-08-01T00:00:00.000Z',
        'current_period_end_date' => '2026-09-01T00:00:00.000Z',
        'canceled_at' => null,
        ...$overrides,
    ];
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function remoteTransaction(string $id, array $overrides = []): array
{
    return [
        'id' => $id,
        'object' => 'transaction',
        'amount' => 1900,
        'amount_paid' => 1900,
        'currency' => 'USD',
        'type' => 'invoice',
        'status' => 'paid',
        'order' => null,
        'subscription' => 'sub_900001',
        'customer' => 'cust_800001',
        'created_at' => 1_788_220_800_000, // 2026-09-01T00:00:00Z
        ...$overrides,
    ];
}

/**
 * @param  array<int, array<string, mixed>>  $transactions
 */
function fakeCreem(array $subscription, array $transactions): void
{
    Http::fake([
        'https://test-api.creem.io/v1/subscriptions*' => Http::response($subscription),
        'https://test-api.creem.io/v1/transactions/search*' => Http::response([
            'items' => $transactions,
            'pagination' => ['current_page' => 1, 'next_page' => null],
        ]),
    ]);
}

test('a missed renewal moves the subscription on and records the payment', function (): void {
    [$user, $subscription] = reconcileSubscriber();

    fakeCreem(remoteSubscription(), [remoteTransaction('tran_renewal')]);

    $this->artisan('creem:reconcile')->assertSuccessful();

    expect($subscription->refresh()->current_period_end_at?->toDateString())->toBe('2026-09-01')
        ->and($subscription->renews_at?->toDateString())->toBe('2026-09-01')
        ->and($user->fresh()?->plan())->toBe(Plan::Pro);

    $this->assertDatabaseHas('creem_orders', [
        'creem_id' => 'tran_renewal',
        'billable_id' => $user->id,
        'subscription_id' => 'sub_900001',
        'product_id' => 'prod_pro_monthly',
        'amount' => 1900,
        'status' => 'paid',
    ]);
});

test('running it twice records each payment once', function (): void {
    reconcileSubscriber();

    fakeCreem(remoteSubscription(), [remoteTransaction('tran_renewal')]);

    $this->artisan('creem:reconcile')->assertSuccessful();
    $this->artisan('creem:reconcile')->assertSuccessful();

    expect(Order::query()->count())->toBe(2);
});

test('the first payment is not counted beside the checkout that recorded it', function (): void {
    reconcileSubscriber();

    fakeCreem(remoteSubscription(), [
        // Carries the checkout's order: the same row.
        remoteTransaction('tran_first_with_order', ['order' => 'ord_700001', 'created_at' => '2026-07-01T00:00:03Z']),
        // Carries no order, but lands moments after the checkout: the same money.
        remoteTransaction('tran_first_no_order', ['created_at' => '2026-07-01T00:00:03Z']),
    ]);

    $this->artisan('creem:reconcile')->assertSuccessful();

    expect(Order::query()->pluck('creem_id')->all())->toBe(['ord_700001']);
});

test('only money that landed is recorded', function (): void {
    reconcileSubscriber();

    fakeCreem(remoteSubscription(), [
        remoteTransaction('tran_declined', ['status' => 'declined']),
        remoteTransaction('tran_pending', ['status' => 'pending']),
    ]);

    $this->artisan('creem:reconcile')->assertSuccessful();

    expect(Order::query()->count())->toBe(1);
});

test('a cancellation that was never delivered takes the plan away', function (): void {
    [$user] = reconcileSubscriber();

    fakeCreem(remoteSubscription([
        'status' => SubscriptionStatus::Expired->value,
        'current_period_end_date' => '2026-08-01T00:00:00.000Z',
    ]), []);

    $this->artisan('creem:reconcile')->assertSuccessful();

    expect(Subscription::query()->sole()->status)->toBe(SubscriptionStatus::Expired->value)
        ->and($user->fresh()?->plan())->toBe(Plan::Starter);
});

test('finished subscriptions are not asked about again', function (): void {
    reconcileSubscriber(SubscriptionStatus::Expired->value);

    fakeCreem(remoteSubscription(), []);

    $this->artisan('creem:reconcile')->assertSuccessful();

    Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), '/subscriptions?'));
});

test('one failure is reported without stopping the rest', function (): void {
    reconcileSubscriber();

    Http::fake([
        'https://test-api.creem.io/v1/subscriptions*' => Http::response(['error' => 'unavailable'], 503),
        'https://test-api.creem.io/v1/transactions/search*' => Http::response([
            'items' => [remoteTransaction('tran_renewal')],
            'pagination' => ['next_page' => null],
        ]),
    ]);

    $this->artisan('creem:reconcile')->assertFailed();

    $this->assertDatabaseHas('creem_orders', ['creem_id' => 'tran_renewal']);
});

test('an environment without creem does nothing', function (): void {
    config(['creem.api_key' => null]);
    Http::fake();

    $this->artisan('creem:reconcile')->assertSuccessful();

    Http::assertNothingSent();
});

test('it is scheduled', function (): void {
    $this->artisan('schedule:list')
        ->expectsOutputToContain('creem:reconcile')
        ->assertSuccessful();
});
