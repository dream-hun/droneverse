<?php

declare(strict_types=1);

use App\Enums\AdminPermission;
use App\Models\Order;
use App\Models\Subscription;
use App\Models\User;
use Inertia\Testing\AssertableInertia;

test('staff without view_finance cannot see the money', function (): void {
    $support = User::factory()->withPermissions([AdminPermission::AccessAdmin, AdminPermission::ManageUsers])->create();

    $this->actingAs($support)->get(route('admin.finance'))->assertForbidden();
});

test('revenue is net of refunds, and a refund with no amount is a full one', function (): void {
    $finance = User::factory()->withPermissions([AdminPermission::AccessAdmin, AdminPermission::ViewFinance])->create();

    Order::factory()->create(['amount' => 1900, 'currency' => 'USD']);
    Order::factory()->refunded(500)->create(['amount' => 1900, 'currency' => 'USD']);
    Order::factory()->create(['amount' => 1900, 'currency' => 'USD', 'refunded' => true, 'refunded_amount' => null]);

    $this->actingAs($finance)
        ->get(route('admin.finance'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->component('admin/finance/index')
            ->loadDeferredProps(fn (AssertableInertia $reload): AssertableInertia => $reload
                ->where('report.totals.allTime.net', '$33.00')
                ->where('report.totals.allTime.refunded', '$24.00')
                ->where('report.totals.allTime.orders', 3)));
});

test('orders in another currency are counted, not summed', function (): void {
    $admin = User::factory()->admin()->create();

    Order::factory()->create(['amount' => 1900, 'currency' => 'USD']);
    Order::factory()->create(['amount' => 5000, 'currency' => 'EUR']);

    $this->actingAs($admin)
        ->get(route('admin.finance'))
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->loadDeferredProps(fn (AssertableInertia $reload): AssertableInertia => $reload
                ->where('report.totals.allTime.net', '$19.00')
                ->where('report.otherCurrencyOrders', 1)));
});

test('recurring revenue prices paying subscriptions at list and leaves trials out', function (): void {
    config()->set('plans.prices.pro.monthly', 'prod_pro_monthly');
    config()->set('plans.prices.pro.yearly', 'prod_pro_yearly');

    $admin = User::factory()->admin()->create();

    Subscription::factory()->selling('prod_pro_monthly')->create();
    Subscription::factory()->selling('prod_pro_yearly')->create();
    Subscription::factory()->selling('prod_pro_monthly')->trialing()->create();
    Subscription::factory()->selling('prod_pro_monthly')->canceled()->create();

    // 19.00 a month, plus 190.00 a year spread over twelve months.
    $this->actingAs($admin)
        ->get(route('admin.finance'))
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->loadDeferredProps(fn (AssertableInertia $reload): AssertableInertia => $reload
                ->where('report.subscriptions.mrr', '$34.83')
                ->where('report.subscriptions.entitled', 3)));
});

test("orders can be found by the pilot's address", function (): void {
    $admin = User::factory()->admin()->create();
    $buyer = User::factory()->create(['email' => 'buyer@example.com']);
    Order::factory()->billable($buyer)->create();
    Order::factory()->create();

    $this->actingAs($admin)
        ->get(route('admin.finance.orders', ['q' => 'buyer@']))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->component('admin/finance/orders')
            ->has('orders', 1)
            ->where('orders.0.pilot.email', 'buyer@example.com'));
});

test('orders can be narrowed to refunds', function (): void {
    $admin = User::factory()->admin()->create();
    Order::factory()->refunded()->create();
    Order::factory()->create();

    $this->actingAs($admin)
        ->get(route('admin.finance.orders', ['refunded' => 'yes']))
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->has('orders', 1)
            ->where('orders.0.refunded', true));
});

test('subscriptions can be narrowed to one status', function (): void {
    $admin = User::factory()->admin()->create();
    Subscription::factory()->canceled()->create();
    Subscription::factory()->create();

    $this->actingAs($admin)
        ->get(route('admin.finance.subscriptions', ['status' => 'canceled']))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->component('admin/finance/subscriptions')
            ->has('subscriptions', 1)
            ->where('subscriptions.0.status', 'canceled')
            ->where('subscriptions.0.entitles', false));
});
