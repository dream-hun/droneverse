<?php

declare(strict_types=1);

use App\Enums\Plan;
use App\Models\User;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia;

describe('billing settings', function (): void {
    test('billing requires an account', function (): void {
        $this->get(route('billing.edit'))->assertRedirect(route('login'));
    });

    test('a starter pilot is offered the upgrade to pro, monthly or yearly', function (): void {
        fakeKelviq();

        $this->actingAs(User::factory()->create())
            ->get(route('billing.edit'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
                ->component('settings/billing')
                ->where('plan.value', 'starter')
                ->where('plan.source', 'none')
                ->where('upgrade.value', 'pro')
                ->where('upgrade.prices.monthly.formatted', '$19')
                ->where('upgrade.prices.monthly.purchasable', true)
                ->where('upgrade.prices.yearly.formatted', '$190')
                ->where('upgrade.prices.yearly.savingPercent', 17)
                ->where('upgrade.prices.lifetime.formatted', '$350')
                ->where('upgrade.prices.lifetime.purchasable', true)
                ->where('upgrade.prices.lifetime.savingPercent', null)
                ->where('canManageBilling', false));
    });

    test('the upgrade renders but cannot be bought where kelviq is not configured', function (): void {
        $this->actingAs(User::factory()->create())
            ->get(route('billing.edit'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
                ->where('upgrade.prices.monthly.purchasable', false)
                ->where('upgrade.prices.yearly.purchasable', false));
    });

    test('a kelviq subscriber is sent to the portal rather than offered pro again', function (): void {
        $user = User::factory()->create();
        fakeKelviq([$user->uuid => ['full-catalog']]);

        $this->actingAs($user)
            ->get(route('billing.edit'))
            ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
                ->where('plan.value', 'pro')
                ->where('plan.source', 'kelviq')
                ->where('upgrade', null)
                ->where('canManageBilling', true));
    });

    test('a comped account is reported as an override with nothing to manage', function (): void {
        $this->actingAs(User::factory()->onPlan(Plan::Pro)->create())
            ->get(route('billing.edit'))
            ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
                ->where('plan.source', 'override')
                ->where('upgrade', null)
                ->where('canManageBilling', false));
    });
});

describe('checkout', function (): void {
    test('checkout requires an account', function (): void {
        $this->post(route('checkout.store'), ['plan' => 'pro', 'variant' => 'monthly'])
            ->assertRedirect(route('login'));
    });

    test('checkout hands the pilot to the hosted checkout kelviq mints', function (string $variant, string $chargePeriod): void {
        $user = User::factory()->create(['email' => 'pilot@example.com']);
        fakeCheckout();

        $this->actingAs($user)
            ->post(route('checkout.store'), ['plan' => 'pro', 'variant' => $variant])
            ->assertRedirect('https://kelviq.com/checkout/cs_123/');

        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://sandboxapi.kelviq.com/api/v1/checkout/'
            && $request->data() === [
                'plan_identifier' => 'pro',
                'charge_period' => $chargePeriod,
                'customer_id' => $user->uuid,
                'success_url' => route('subscription.thank-you'),
                'cancel_url' => route('billing.edit'),
                'email' => 'pilot@example.com',
                'lock_email' => true,
            ]);
    })->with([
        'monthly' => ['monthly', 'MONTHLY'],
        'yearly' => ['yearly', 'YEARLY'],
    ]);

    test('lifetime is checked out once, from its own kelviq plan', function (): void {
        $user = User::factory()->create();
        fakeCheckout();

        $this->actingAs($user)
            ->post(route('checkout.store'), ['plan' => 'pro', 'variant' => 'lifetime'])
            ->assertRedirect('https://kelviq.com/checkout/cs_123/');

        Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/checkout/')
            && $request['plan_identifier'] === 'pro-lifetime'
            && $request['charge_period'] === 'ONE_TIME'
            && $request['customer_id'] === $user->uuid);
    });

    test('an inertia visit is sent on with a location rather than a redirect it cannot follow', function (): void {
        fakeCheckout();

        $this->actingAs(User::factory()->create())
            ->withHeaders(['X-Inertia' => 'true'])
            ->post(route('checkout.store'), ['plan' => 'pro', 'variant' => 'yearly'])
            ->assertStatus(409)
            ->assertHeader('X-Inertia-Location', 'https://kelviq.com/checkout/cs_123/');
    });

    test('the success url is absolute, as kelviq requires', function (): void {
        fakeCheckout();

        $this->actingAs(User::factory()->create())
            ->post(route('checkout.store'), ['plan' => 'pro', 'variant' => 'monthly']);

        Http::assertSent(function (Request $request): bool {
            $successUrl = $request->data()['success_url'] ?? null;

            return is_string($successUrl) && filter_var($successUrl, FILTER_VALIDATE_URL) !== false;
        });
    });

    /**
     * Asked of Kelviq afresh, so a pilot who paid a minute ago is not sold
     * the same plan again on a cached "no".
     */
    test('a pilot already on pro is sent to billing rather than sold it twice', function (): void {
        $user = User::factory()->create();
        fakeCheckout([$user->uuid => ['full-catalog']]);
        cache()->put('kelviq:entitlements:'.$user->uuid, [], 60);

        $this->actingAs($user)
            ->post(route('checkout.store'), ['plan' => 'pro', 'variant' => 'monthly'])
            ->assertRedirect(route('billing.edit'))
            ->assertInertiaFlash('toast.message', 'You are already on Pro. Manage it from billing settings.');

        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), '/checkout/'));
    });

    test('a comped pilot has nothing to buy', function (): void {
        fakeCheckout();

        $this->actingAs(User::factory()->onPlan(Plan::Pro)->create())
            ->post(route('checkout.store'), ['plan' => 'pro', 'variant' => 'monthly'])
            ->assertRedirect(route('billing.edit'));
    });

    test('checkout refuses what is not for sale without reaching kelviq', function (string $plan, string $variant): void {
        fakeCheckout();

        $this->actingAs(User::factory()->create())
            ->from(route('pricing'))
            ->post(route('checkout.store'), ['plan' => $plan, 'variant' => $variant])
            ->assertRedirect(route('pricing'))
            ->assertInertiaFlash('toast.type', 'error');

        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), '/checkout/'));
    })->with([
        'the free tier' => ['starter', 'monthly'],
        'a period pro does not sell' => ['pro', 'weekly'],
    ]);

    test('an environment with no kelviq key cannot sell anything', function (): void {
        Http::preventStrayRequests();

        $this->actingAs(User::factory()->create())
            ->from(route('pricing'))
            ->post(route('checkout.store'), ['plan' => 'pro', 'variant' => 'monthly'])
            ->assertRedirect(route('pricing'))
            ->assertInertiaFlash('toast.message', 'Pro is not available for purchase right now.');
    });

    test('checkout refuses a plan that does not exist, or no period at all', function (): void {
        $this->actingAs(User::factory()->create())
            ->post(route('checkout.store'), ['plan' => 'team', 'variant' => ''])
            ->assertSessionHasErrors(['plan', 'variant']);
    });

    test('a kelviq outage is a sentence rather than a five hundred', function (): void {
        fakeKelviq(responses: ['sandboxapi.kelviq.com/api/v1/checkout/' => Http::response(['detail' => 'down'], 500)]);

        $this->actingAs(User::factory()->create())
            ->from(route('billing.edit'))
            ->post(route('checkout.store'), ['plan' => 'pro', 'variant' => 'monthly'])
            ->assertRedirect(route('billing.edit'))
            ->assertInertiaFlash('toast.message', 'Checkout could not be opened right now. Please try again in a moment.');
    });
});

describe('billing portal', function (): void {
    test('the portal is kelviqs own, signed with the session token', function (): void {
        $user = User::factory()->create();
        fakeKelviq(responses: ['sandboxapi.kelviq.com/api/v1/portal/session/' => Http::response([
            'token' => 'cpt-abc:def',
            'email' => 'pilot@example.com',
            'customerPortalUrl' => 'https://www.kelviq.com/portal/droneverse/',
        ])]);

        $this->actingAs($user)
            ->get(route('billing-portal.edit'))
            ->assertRedirect('https://www.kelviq.com/portal/droneverse/?token=cpt-abc:def');

        Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/portal/session/')
            && $request['customer_id'] === $user->uuid);
    });

    /**
     * 400 is Kelviq's answer for a customer it has never seen, and for one it
     * holds without an email — somebody who has never reached checkout.
     */
    test('a pilot kelviq has never billed is told so rather than shown a five hundred', function (): void {
        fakeKelviq(responses: ['sandboxapi.kelviq.com/api/v1/portal/session/' => Http::response(['customerId' => ['Customer not found.']], 400)]);

        $this->actingAs(User::factory()->create())
            ->get(route('billing-portal.edit'))
            ->assertRedirect(route('billing.edit'))
            ->assertInertiaFlash('toast.message', 'There is no billing account to manage yet. It appears once you upgrade.');
    });

    test('a portal kelviq cannot open is reported rather than thrown', function (mixed $response): void {
        fakeKelviq(responses: ['sandboxapi.kelviq.com/api/v1/portal/session/' => $response]);

        $this->actingAs(User::factory()->create())
            ->get(route('billing-portal.edit'))
            ->assertRedirect(route('billing.edit'))
            ->assertInertiaFlash('toast.message', 'The billing portal could not be opened. Please try again.');
    })->with([
        'a server error' => fn () => Http::response('down', 503),
        'no connection' => fn () => Http::failedConnection(),
    ]);

    test('an environment with no kelviq key has no portal', function (): void {
        Http::preventStrayRequests();

        $this->actingAs(User::factory()->create())
            ->get(route('billing-portal.edit'))
            ->assertRedirect(route('billing.edit'))
            ->assertInertiaFlash('toast.message', 'There is no billing account to manage yet.');
    });
});

/**
 * @param  array<string, array<int, string>>  $entitlements
 */
function fakeCheckout(array $entitlements = []): void
{
    fakeKelviq($entitlements, ['sandboxapi.kelviq.com/api/v1/checkout/' => Http::response([
        'checkoutSessionId' => 'cs_123',
        'checkoutUrl' => 'https://kelviq.com/checkout/cs_123/',
    ], 201)]);
}
