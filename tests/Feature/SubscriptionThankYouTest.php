<?php

declare(strict_types=1);

use App\Enums\Plan;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia;

test('the thank-you page requires an account', function (): void {
    $this->get(route('subscription.thank-you'))->assertRedirect(route('login'));
});

test('a buyer kelviq has not granted yet is told the plan is pending', function (): void {
    fakeKelviq();

    $this->actingAs(User::factory()->create())
        ->get(route('subscription.thank-you'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->component('subscription/thank-you')
            ->where('plan.value', 'starter')
            ->where('highlights', [])
            ->where('pending', true));
});

test('a buyer kelviq has granted is welcomed to pro', function (): void {
    $user = User::factory()->create();
    fakeKelviq([$user->uuid => ['full-catalog']]);

    $this->actingAs($user)
        ->get(route('subscription.thank-you'))
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->where('plan.value', 'pro')
            ->where('plan.isPaid', true)
            ->where('highlights', Plan::Pro->highlights())
            ->where('pending', false)
            ->where('auth.plan.value', 'pro'));
});

/**
 * The page is polled while it waits, and every tick has to ask Kelviq rather
 * than read a minute-old "no" back out of the cache.
 */
test('every visit asks kelviq afresh', function (): void {
    $user = User::factory()->create();
    fakeKelviq([$user->uuid => ['full-catalog']]);
    Cache::put('kelviq:entitlements:'.$user->uuid, [], 60);

    $this->actingAs($user)
        ->get(route('subscription.thank-you'))
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page->where('pending', false));

    Http::assertSentCount(1);
});

test('a comped account is confirmed rather than left waiting', function (): void {
    $this->actingAs(User::factory()->onPlan(Plan::Pro)->create())
        ->get(route('subscription.thank-you'))
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page->where('pending', false));
});
