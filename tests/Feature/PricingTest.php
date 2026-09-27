<?php

declare(strict_types=1);

use App\Enums\Plan;
use App\Models\User;
use Inertia\Testing\AssertableInertia;

test('a guest can read the pricing page', function (): void {
    $this->get(route('pricing'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->component('pricing')
            ->has('plans', 2)
            ->where('plans.0.value', 'starter')
            ->where('plans.1.value', 'pro')
            ->where('plans.1.prices.monthly.formatted', '$19')
            ->where('plans.1.prices.yearly.formatted', '$190')
            ->where('plans.1.prices.lifetime.formatted', '$350')
            ->where('plans.1.isPopular', true));
});

test('the page renders where checkout cannot open at all', function (): void {
    $this->actingAs(User::factory()->create())
        ->get(route('pricing'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->where('plans.1.cta.action', 'unavailable')
            ->where('plans.1.prices.monthly.purchasable', false)
            ->where('plans.1.prices.yearly.purchasable', false));
});

test('a guest is sent to sign up rather than to checkout', function (): void {
    fakeKelviq();

    $this->get(route('pricing'))
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->where('plans.0.cta.action', 'signup')
            ->where('plans.0.isCurrent', false)
            ->where('plans.1.cta.action', 'signup')
            ->where('plans.1.cta.label', 'Get Pro'));
});

test('a starter pilot is offered checkout on pro, monthly and yearly', function (): void {
    fakeKelviq();

    $this->actingAs(User::factory()->create())
        ->get(route('pricing'))
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->where('plans.0.cta.action', 'current')
            ->where('plans.0.isCurrent', true)
            ->where('plans.1.cta.action', 'checkout')
            ->where('plans.1.cta.label', 'Upgrade to Pro')
            ->where('plans.1.prices.monthly.purchasable', true)
            ->where('plans.1.prices.yearly.purchasable', true)
            ->where('plans.1.prices.lifetime.purchasable', true));
});

test('a pro pilot is not sold pro again', function (): void {
    fakeKelviq();

    $this->actingAs(User::factory()->onPlan(Plan::Pro)->create())
        ->get(route('pricing'))
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->where('plans.0.cta.action', 'included')
            ->where('plans.1.cta.action', 'current')
            ->where('plans.1.isCurrent', true)
            ->where('plans.1.isPopular', false));
});

test('a kelviq subscriber is shown pro as their plan', function (): void {
    $user = User::factory()->create();
    fakeKelviq([$user->uuid => ['full-catalog']]);

    $this->actingAs($user)
        ->get(route('pricing'))
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->where('plans.1.cta.action', 'current'));
});

test('the annual saving is computed from the prices it describes', function (): void {
    $this->get(route('pricing'))
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->where('plans.1.prices.yearly.savingPercent', 17)
            ->where('plans.1.prices.monthly.savingPercent', null));
});

test('a period with no quoted amount is left off the page', function (): void {
    config(['plans.amounts.pro' => ['monthly' => 1900]]);

    $this->get(route('pricing'))
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->has('plans.1.prices', 1)
            ->has('plans.1.prices.monthly'));
});

test('no annual saving is claimed without a monthly price to compare against', function (): void {
    config(['plans.amounts.pro' => ['monthly' => 0, 'yearly' => 19000]]);

    $this->get(route('pricing'))
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->where('plans.1.prices.yearly.savingPercent', null));
});

test('no annual saving is claimed when a year costs no less than twelve months', function (): void {
    config(['plans.amounts.pro' => ['monthly' => 1000, 'yearly' => 12000], 'plans.currency' => null]);

    $this->get(route('pricing'))
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->where('plans.1.prices.yearly.savingPercent', null)
            ->where('plans.1.prices.yearly.formatted', '$120'));
});

test('the comparison grid marks unbuilt capabilities', function (): void {
    $this->get(route('pricing'))
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->where('comparison.0.value', 'mission_builder')
            ->where('comparison.0.available', false)
            ->where('comparison.0.plans', [false, true])
            ->where('comparison.1.value', 'drone_config_editor')
            ->where('comparison.1.available', true));
});

test('the client is never handed a kelviq plan identifier or key', function (): void {
    fakeKelviq();

    $this->get(route('pricing'))
        ->assertDontSee('kq_sandbox_test_key')
        ->assertDontSee('plan_identifier');
});
