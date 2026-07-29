<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\Plan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Paddle\Customer;
use Tests\TestCase;

final class PricingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'plans.prices' => [
                'pro' => [
                    'monthly' => 'pri_pro_monthly',
                    'yearly' => 'pri_pro_yearly',
                ],
                /*
                 * Priced but not self-serve: Team's classroom tools do not
                 * exist yet, so a configured price ID must still not produce a
                 * checkout button.
                 */
                'team' => ['monthly' => 'pri_team_monthly'],
            ],
            'plans.sales_email' => 'sales@example.test',
        ]);
    }

    public function test_a_guest_can_read_the_pricing_page(): void
    {
        $this->get(route('pricing'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('pricing')
                ->has('plans', count(Plan::cases()))
                ->where('salesEmail', 'sales@example.test'));
    }

    public function test_a_guest_is_sent_to_sign_up_rather_than_to_checkout(): void
    {
        $this->get(route('pricing'))
            ->assertInertia(fn ($page) => $page
                ->where('plans.0.cta.action', 'signup')
                ->where('plans.1.cta.action', 'signup'));
    }

    public function test_a_starter_pilot_is_offered_checkout_on_pro(): void
    {
        $this->actingAs(User::factory()->create())
            ->get(route('pricing'))
            ->assertInertia(fn ($page) => $page
                ->where('plans.0.isCurrent', true)
                ->where('plans.0.cta.action', 'current')
                ->where('plans.1.value', 'pro')
                ->where('plans.1.cta.action', 'checkout')
                ->where('plans.1.isPopular', true));
    }

    public function test_a_pro_pilot_is_not_sold_pro_again(): void
    {
        $this->actingAs(User::factory()->onPlan(Plan::Pro)->create())
            ->get(route('pricing'))
            ->assertInertia(fn ($page) => $page
                ->where('plans.1.cta.action', 'current')
                ->where('plans.1.isPopular', false)
                // Starter is beneath them, and saying so beats an upgrade
                // button pointing downhill.
                ->where('plans.0.cta.action', 'included'));
    }

    /**
     * The card quotes its price from `plans.amounts` and its button from
     * `plans.prices`. An environment with the first and not the second — every
     * environment with no Paddle catalogue behind it — must still render honest
     * copy above a button that refuses.
     */
    public function test_an_unpriced_tier_is_not_for_sale_however_confidently_it_is_quoted(): void
    {
        config(['plans.prices.pro' => []]);

        $this->actingAs(User::factory()->create())
            ->get(route('pricing'))
            ->assertInertia(fn ($page) => $page
                ->where('plans.1.value', 'pro')
                ->where('plans.1.cta.action', 'unavailable')
                // The copy still stands; only the button is off.
                ->where('plans.1.prices.monthly.formatted', '$19'));
    }

    public function test_team_and_enterprise_point_at_sales_rather_than_checkout(): void
    {
        $this->actingAs(User::factory()->create())
            ->get(route('pricing'))
            ->assertInertia(fn ($page) => $page
                ->where('plans.2.value', 'team')
                ->where('plans.2.cta.action', 'contact')
                ->where('plans.3.value', 'enterprise')
                ->where('plans.3.cta.action', 'contact'));
    }

    public function test_the_annual_saving_is_computed_from_the_prices_it_describes(): void
    {
        $this->get(route('pricing'))
            ->assertInertia(fn ($page) => $page
                ->where('plans.1.prices.monthly.formatted', '$19')
                ->where('plans.1.prices.yearly.formatted', '$190')
                ->where('plans.1.prices.yearly.savingPercent', 17)
                ->where('plans.1.prices.monthly.savingPercent', null));
    }

    public function test_the_comparison_grid_marks_unbuilt_capabilities(): void
    {
        $this->get(route('pricing'))
            ->assertInertia(fn ($page) => $page
                ->where('comparison.0.value', 'python_runtime')
                ->where('comparison.0.available', false)
                // Starter, Pro, Team, Enterprise.
                ->where('comparison.0.plans', [false, true, true, true]));
    }

    public function test_the_client_is_never_handed_a_price_id(): void
    {
        $response = $this->actingAs(User::factory()->create())->get(route('pricing'));

        $response->assertOk();
        $response->assertDontSee('pri_pro_monthly');
        $response->assertDontSee('pri_team_monthly');
    }

    public function test_checkout_requires_an_account(): void
    {
        $this->post(route('checkout.store'), ['plan' => 'pro', 'variant' => 'monthly'])
            ->assertRedirect(route('login'));
    }

    public function test_checkout_returns_overlay_options_carrying_the_resolved_price(): void
    {
        $user = $this->customerFor(User::factory()->create());

        $response = $this->actingAs($user)
            ->postJson(route('checkout.store'), ['plan' => 'pro', 'variant' => 'yearly']);

        $response->assertOk();
        $response->assertJsonPath('checkout.items.0.priceId', 'pri_pro_yearly');
        $response->assertJsonPath('checkout.settings.displayMode', 'overlay');
        $response->assertJsonPath('checkout.customData.plan', 'pro');
        $response->assertJsonPath('checkout.customData.variant', 'yearly');
        $response->assertJsonPath('checkout.customer.id', $user->customer->paddle_id);
    }

    /**
     * A successUrl navigates the browser away the instant Paddle has the money,
     * which tears the pricing page down before its checkout.completed handler
     * can poll for the entitlement — and the plan is granted by a webhook that
     * has not necessarily arrived, so the buyer lands on a fresh page still
     * showing the plan they just paid to leave.
     *
     * allowLogout is asserted alongside it because array_filter would drop it:
     * the value it needs to send is false, and false does not survive a filter.
     */
    public function test_checkout_leaves_the_browser_on_the_pricing_page(): void
    {
        $user = $this->customerFor(User::factory()->create());

        $response = $this->actingAs($user)
            ->postJson(route('checkout.store'), ['plan' => 'pro', 'variant' => 'monthly']);

        $response->assertOk();
        $response->assertJsonMissingPath('checkout.settings.successUrl');
        $response->assertJsonPath('checkout.settings.allowLogout', false);
    }

    public function test_checkout_refuses_a_billing_period_the_plan_does_not_sell(): void
    {
        $user = $this->customerFor(User::factory()->create());

        $this->actingAs($user)
            ->postJson(route('checkout.store'), ['plan' => 'pro', 'variant' => 'weekly'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('plan');
    }

    /**
     * The ordering rule from docs/pricing-implementation-plan.md, enforced
     * where it matters rather than trusted to the pricing page's markup.
     */
    public function test_checkout_refuses_a_sales_led_tier(): void
    {
        $user = $this->customerFor(User::factory()->create());

        $this->actingAs($user)
            ->postJson(route('checkout.store'), ['plan' => 'team', 'variant' => 'monthly'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('plan');
    }

    public function test_checkout_refuses_a_tier_with_no_configured_price(): void
    {
        config(['plans.prices.pro' => []]);

        $user = $this->customerFor(User::factory()->create());

        $this->actingAs($user)
            ->postJson(route('checkout.store'), ['plan' => 'pro', 'variant' => 'monthly'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('plan');
    }

    public function test_checkout_refuses_a_plan_that_does_not_exist(): void
    {
        $user = $this->customerFor(User::factory()->create());

        $this->actingAs($user)
            ->postJson(route('checkout.store'), ['plan' => 'platinum', 'variant' => 'monthly'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('plan');
    }

    /**
     * Give the user a Paddle customer record so checkout has no reason to
     * reach the Paddle API.
     */
    private function customerFor(User $user): User
    {
        Customer::query()->create([
            'billable_id' => $user->id,
            'billable_type' => $user->getMorphClass(),
            'paddle_id' => 'ctm_test_'.$user->id,
            'name' => $user->name,
            'email' => $user->email,
        ]);

        return $user->refresh();
    }
}
