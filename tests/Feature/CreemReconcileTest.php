<?php

declare(strict_types=1);

use App\Actions\RecordCreemRefund;
use App\Actions\SyncCreemOrder;
use App\Enums\Plan;
use App\Enums\SubscriptionStatus;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
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
 * A transaction as `GET /v1/transactions/search` returns it.
 *
 * `order` is the checkout's, because that is how Creem sends a renewal: a
 * subscription has one order and every payment on it is a transaction against
 * that order. The first version of this fixture sent `null` there, and so
 * never saw every real renewal being dropped as the checkout arriving again.
 *
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
        'order' => 'ord_700001',
        'subscription' => 'sub_900001',
        'customer' => 'cust_800001',
        'created_at' => 1_788_220_800_000, // 2026-09-01T00:00:00Z
        ...$overrides,
    ];
}

/**
 * @param  array<string, mixed>  $subscription
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

    expect(Artisan::call('creem:reconcile'))->toBe(Command::SUCCESS);

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

    expect(Artisan::call('creem:reconcile'))->toBe(Command::SUCCESS);
    expect(Artisan::call('creem:reconcile'))->toBe(Command::SUCCESS);

    expect(Order::query()->count())->toBe(2);
});

test('renewals sharing the checkout order are each recorded', function (): void {
    [$user] = reconcileSubscriber();

    fakeCreem(remoteSubscription(), [
        remoteTransaction('tran_october', ['created_at' => 1_790_812_800_000]), // 2026-10-01
        remoteTransaction('tran_september'),
        remoteTransaction('tran_first', ['created_at' => '2026-07-01T00:00:03Z']),
    ]);

    expect(Artisan::call('creem:reconcile'))->toBe(Command::SUCCESS);

    // Read once: fetching the buffered output empties it.
    $output = Artisan::output();

    expect($output)->toMatch('/Paid transactions at Creem\W+3/')
        ->and($output)->toMatch('/Missing payments recorded\W+2/');

    expect(Order::query()->oldest('ordered_at')->pluck('creem_id')->all())
        ->toBe(['ord_700001', 'tran_september', 'tran_october'])
        ->and(Order::query()->where('billable_id', $user->id)->sum('amount'))->toEqual(5700);
});

test('the first payment is matched to the checkout rather than counted beside it', function (): void {
    reconcileSubscriber();

    fakeCreem(remoteSubscription(), [
        remoteTransaction('tran_first', ['created_at' => '2026-07-01T00:00:03Z']),
    ]);

    expect(Artisan::call('creem:reconcile'))->toBe(Command::SUCCESS);

    expect(Order::query()->sole()->only(['creem_id', 'transaction_id']))
        ->toBe(['creem_id' => 'ord_700001', 'transaction_id' => 'tran_first']);
});

test('a first payment naming no order is matched by its subscription', function (): void {
    reconcileSubscriber();

    fakeCreem(remoteSubscription(), [
        remoteTransaction('tran_first', ['order' => null, 'created_at' => '2026-07-01T00:00:03Z']),
    ]);

    expect(Artisan::call('creem:reconcile'))->toBe(Command::SUCCESS);

    expect(Order::query()->sole()->transaction_id)->toBe('tran_first');
});

test('a refund of one renewal marks that renewal and not the checkout', function (): void {
    reconcileSubscriber();

    fakeCreem(remoteSubscription(), [remoteTransaction('tran_september')]);
    Artisan::call('creem:reconcile');

    resolve(RecordCreemRefund::class)->handle([
        'id' => 'ref_1',
        'object' => 'refund',
        'refund_amount' => 1900,
        'order' => 'ord_700001',
        'transaction' => remoteTransaction('tran_september', ['status' => 'refunded']),
    ]);

    expect(Order::query()->where('creem_id', 'tran_september')->firstOrFail()->refunded)->toBeTrue()
        ->and(Order::query()->where('creem_id', 'ord_700001')->firstOrFail()->refunded)->toBeFalse();
});

test('a redelivered checkout keeps the transaction matched to it', function (): void {
    [$user] = reconcileSubscriber();

    Order::query()->where('creem_id', 'ord_700001')->update(['transaction_id' => 'tran_first']);

    resolve(SyncCreemOrder::class)->handle($user, [
        'id' => 'ch_1',
        'customer' => 'cust_800001',
        'product' => 'prod_pro_monthly',
        'order' => ['id' => 'ord_700001', 'amount' => 1900, 'currency' => 'USD', 'status' => 'paid'],
    ]);

    expect(Order::query()->sole()->transaction_id)->toBe('tran_first');
});

test('only money that landed is recorded', function (): void {
    reconcileSubscriber();

    fakeCreem(remoteSubscription(), [
        remoteTransaction('tran_declined', ['status' => 'declined']),
        remoteTransaction('tran_pending', ['status' => 'pending']),
    ]);

    expect(Artisan::call('creem:reconcile'))->toBe(Command::SUCCESS);

    expect(Order::query()->count())->toBe(1);
});

test('a cancellation that was never delivered takes the plan away', function (): void {
    [$user] = reconcileSubscriber();

    fakeCreem(remoteSubscription([
        'status' => SubscriptionStatus::Expired->value,
        'current_period_end_date' => '2026-08-01T00:00:00.000Z',
    ]), []);

    expect(Artisan::call('creem:reconcile'))->toBe(Command::SUCCESS);

    expect(Subscription::query()->sole()->status)->toBe(SubscriptionStatus::Expired->value)
        ->and($user->fresh()?->plan())->toBe(Plan::Starter);
});

test('finished subscriptions are not asked about again', function (): void {
    reconcileSubscriber(SubscriptionStatus::Expired->value);

    fakeCreem(remoteSubscription(), []);

    expect(Artisan::call('creem:reconcile'))->toBe(Command::SUCCESS);

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

    expect(Artisan::call('creem:reconcile'))->toBe(Command::FAILURE);

    $this->assertDatabaseHas('creem_orders', ['creem_id' => 'tran_renewal']);
});

test('an environment without creem does nothing', function (): void {
    config(['creem.api_key' => null]);
    Http::fake();

    expect(Artisan::call('creem:reconcile'))->toBe(Command::SUCCESS);

    Http::assertNothingSent();
});

test('it is scheduled', function (): void {
    expect(Artisan::call('schedule:list'))->toBe(Command::SUCCESS)
        ->and(Artisan::output())->toContain('creem:reconcile');
});
