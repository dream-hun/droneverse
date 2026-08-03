<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Actions\ResolvePlanForUser;
use App\Enums\Feature;
use App\Enums\Plan;
use App\Models\User;
use DateTimeInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Route;
use LemonSqueezy\Laravel\Customer;
use LemonSqueezy\Laravel\Subscription;
use Tests\TestCase;

final class EntitlementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['plans.prices' => [
            'pro' => [
                'monthly' => 'var_pro_monthly',
                'yearly' => 'var_pro_yearly',
                'monthly_launch' => 'var_pro_monthly_launch',
            ],
            'team' => ['monthly' => 'var_team_monthly'],
        ]]);
    }

    public function test_a_user_with_nothing_resolves_to_starter(): void
    {
        $user = User::factory()->create();

        $this->assertSame(Plan::Starter, $this->resolve($user));
    }

    public function test_a_guest_resolves_to_starter(): void
    {
        $this->assertSame(Plan::Starter, resolve(ResolvePlanForUser::class)->handle(null));
    }

    public function test_a_plan_override_resolves_to_that_plan(): void
    {
        $user = User::factory()->onPlan(Plan::Pro)->create();

        $this->assertSame(Plan::Pro, $this->resolve($user));
    }

    public function test_a_plan_override_outranks_an_active_subscription(): void
    {
        $user = User::factory()->onPlan(Plan::Enterprise)->create();
        $this->subscribe($user, 'var_pro_monthly');

        $this->assertSame(Plan::Enterprise, $this->resolve($user));
    }

    public function test_an_override_naming_a_retired_plan_falls_through_instead_of_throwing(): void
    {
        $user = User::factory()->create(['plan_override' => 'platinum']);

        $this->assertSame(Plan::Starter, $this->resolve($user));
    }

    /**
     * A Lemon Squeezy subscription names the variant it sells on the row itself,
     * so the plan is read straight off `variant_id`. Paddle's subscription_items
     * join has no counterpart in this schema, and nothing looks for one.
     */
    public function test_an_active_subscription_resolves_through_the_variant_on_its_own_row(): void
    {
        $user = User::factory()->create();
        $subscription = $this->subscribe($user, 'var_pro_monthly');

        $this->assertSame('var_pro_monthly', $subscription->variant_id);
        $this->assertSame(Plan::Pro, $this->resolve($user));
    }

    public function test_a_trialing_subscription_still_grants_its_plan(): void
    {
        $user = User::factory()->create();
        $this->subscribe($user, 'var_pro_yearly', Subscription::STATUS_ON_TRIAL);

        $this->assertSame(Plan::Pro, $this->resolve($user));
    }

    /**
     * A card that bounced is a payment problem, not a decision to leave. Lemon
     * Squeezy retries for days before it gives up, and locking the catalogue on
     * the first failure would punish an expired card harder than a cancellation.
     */
    public function test_a_past_due_subscription_still_grants_its_plan(): void
    {
        $user = User::factory()->create();
        $this->subscribe($user, 'var_pro_monthly', Subscription::STATUS_PAST_DUE);

        $this->assertSame(Plan::Pro, $this->resolve($user));
    }

    /**
     * They bought the month, so they keep it. `valid()` stays true through the
     * grace period after a cancellation, which is what makes CancelSubscription's
     * end-of-period promise hold without a special case anywhere.
     */
    public function test_a_cancelled_subscription_grants_its_plan_until_the_paid_period_runs_out(): void
    {
        $user = User::factory()->create();
        $this->subscribe($user, 'var_pro_monthly', Subscription::STATUS_CANCELLED, endsAt: now()->addWeek());

        $this->assertSame(Plan::Pro, $this->resolve($user));
    }

    public function test_a_cancelled_subscription_grants_nothing_once_the_period_has_run_out(): void
    {
        $user = User::factory()->create();
        $this->subscribe($user, 'var_pro_monthly', Subscription::STATUS_CANCELLED, endsAt: now()->subDay());

        $this->assertSame(Plan::Starter, $this->resolve($user));
    }

    public function test_an_expired_subscription_grants_nothing(): void
    {
        $user = User::factory()->create();
        $this->subscribe($user, 'var_pro_monthly', Subscription::STATUS_EXPIRED);

        $this->assertSame(Plan::Starter, $this->resolve($user));
    }

    /**
     * Lemon Squeezy pauses in two modes and they mean opposite things. A free
     * pause keeps the pilot on the plan while it stops charging them, so the
     * catalogue stays open; a void pause stops the service as well as the
     * billing. `valid()` distinguishes them and this is where that shows.
     */
    public function test_a_free_pause_keeps_the_plan_and_a_void_pause_does_not(): void
    {
        $free = User::factory()->create();
        $this->subscribe($free, 'var_pro_monthly', Subscription::STATUS_PAUSED, pauseMode: 'free');

        $void = User::factory()->create();
        $this->subscribe($void, 'var_pro_monthly', Subscription::STATUS_PAUSED, pauseMode: 'void');

        $this->assertSame(Plan::Pro, $this->resolve($free));
        $this->assertSame(Plan::Starter, $this->resolve($void));
    }

    public function test_the_most_generous_of_several_subscriptions_wins(): void
    {
        $user = User::factory()->create();
        $this->subscribe($user, 'var_pro_monthly');
        $this->subscribe($user, 'var_team_monthly', type: 'classroom');

        $this->assertSame(Plan::Team, $this->resolve($user));
    }

    /**
     * The reverse of the case above, asserted separately because the reduction
     * that picks the winner is order-sensitive and the rows come back in
     * insertion order.
     */
    public function test_the_most_generous_subscription_wins_whichever_was_bought_first(): void
    {
        $user = User::factory()->create();
        $this->subscribe($user, 'var_team_monthly', type: 'classroom');
        $this->subscribe($user, 'var_pro_monthly');

        $this->assertSame(Plan::Team, $this->resolve($user));
    }

    /**
     * An expired Team subscription alongside a live Pro one resolves to Pro:
     * only valid subscriptions are considered, so a plan someone used to hold
     * cannot outrank the one they still pay for.
     */
    public function test_a_lapsed_subscription_does_not_outrank_a_live_one(): void
    {
        $user = User::factory()->create();
        $this->subscribe($user, 'var_team_monthly', Subscription::STATUS_EXPIRED, type: 'classroom');
        $this->subscribe($user, 'var_pro_monthly');

        $this->assertSame(Plan::Pro, $this->resolve($user));
    }

    public function test_a_subscription_to_an_unrecognised_variant_grants_nothing(): void
    {
        $user = User::factory()->create();
        $this->subscribe($user, 'var_some_retired_experiment');

        $this->assertSame(Plan::Starter, $this->resolve($user));
    }

    public function test_one_users_subscription_does_not_leak_to_another(): void
    {
        $subscriber = User::factory()->create();
        $this->subscribe($subscriber, 'var_pro_monthly');

        $bystander = User::factory()->create();

        $this->assertSame(Plan::Pro, $this->resolve($subscriber));
        $this->assertSame(Plan::Starter, $this->resolve($bystander));
    }

    public function test_the_model_answers_feature_questions_from_its_plan(): void
    {
        $starter = User::factory()->create();
        $pro = User::factory()->onPlan(Plan::Pro)->create();

        $this->assertFalse($starter->hasFeature(Feature::PythonRuntime));
        $this->assertFalse($starter->onPaidPlan());
        $this->assertSame([], $starter->features());

        $this->assertTrue($pro->hasFeature(Feature::PythonRuntime));
        $this->assertTrue($pro->onPaidPlan());
        $this->assertTrue($pro->planCovers(Plan::Starter));
    }

    public function test_the_resolved_plan_is_memoised_until_forgotten(): void
    {
        $user = User::factory()->create();

        $this->assertSame(Plan::Starter, $user->plan());

        $user->plan_override = Plan::Pro->value;
        $user->save();

        $this->assertSame(Plan::Starter, $user->plan(), 'The memo should survive a change made after resolution.');
        $this->assertSame(Plan::Pro, $user->forgetPlan()->plan());
    }

    public function test_a_gated_route_rejects_starter_and_admits_pro(): void
    {
        Route::middleware(['web', 'auth', 'can:'.Feature::PythonRuntime->value])
            ->get('__test__/python', fn (): string => 'ok');

        $starter = User::factory()->create();
        $pro = User::factory()->onPlan(Plan::Pro)->create();

        $this->actingAs($starter)->get('__test__/python')->assertForbidden();
        $this->actingAs($pro)->get('__test__/python')->assertOk();
    }

    public function test_every_feature_is_registered_as_a_gate(): void
    {
        $enterprise = User::factory()->onPlan(Plan::Enterprise)->create();
        $starter = User::factory()->create();

        foreach (Feature::cases() as $feature) {
            $this->assertTrue($enterprise->can($feature->value), $feature->value);
            $this->assertFalse($starter->can($feature->value), $feature->value);
        }
    }

    public function test_entitlements_are_shared_with_the_front_end(): void
    {
        $user = User::factory()->onPlan(Plan::Pro)->create();

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertInertia(fn ($page) => $page
                ->where('auth.plan.value', 'pro')
                ->where('auth.plan.label', 'Pro')
                ->where('auth.plan.isPaid', true)
                ->where('auth.features', fn (Collection $features): bool => $features->contains('python_runtime')
                    && $features->doesntContain('team_management')));
    }

    public function test_guests_are_shared_the_starter_plan(): void
    {
        $this->get(route('home'))
            ->assertInertia(fn ($page) => $page
                ->where('auth.plan.value', 'starter')
                ->where('auth.features', []));
    }

    public function test_the_plan_override_is_never_serialised_to_the_client(): void
    {
        $user = User::factory()->onPlan(Plan::Enterprise)->create();

        $this->assertArrayNotHasKey('plan_override', $user->toArray());
    }

    /**
     * Decision 2: nobody who signed up while the platform was free loses
     * access on the day it stops being free.
     *
     * The migration runs against an empty table in a test database, so it is
     * invoked directly here against accounts that stand in for the pre-launch
     * ones. Anyone already carrying an override — comped, staff, academic — is
     * left exactly as they were.
     */
    public function test_pre_launch_accounts_are_grandfathered_to_pro(): void
    {
        $preLaunch = User::factory()->create();
        $comped = User::factory()->onPlan(Plan::Enterprise)->create();

        $migration = require database_path('migrations/2026_07_28_092306_backfill_pre_launch_users_to_pro.php');
        $migration->up();

        $this->assertSame(Plan::Pro, $this->resolve($preLaunch));
        $this->assertSame(Plan::Enterprise, $this->resolve($comped));
    }

    private function resolve(User $user): Plan
    {
        return resolve(ResolvePlanForUser::class)->handle($user->fresh());
    }

    /**
     * Give the user a Lemon Squeezy subscription to a single variant, alongside
     * the one customer row a real account has.
     *
     * Built with the package's factory but saved by hand, because its
     * afterCreating hook creates a customer per subscription and
     * lemon_squeezy_customers is unique on (billable_id, billable_type) — a
     * second subscription for the same pilot, which several tests below need,
     * would collide on it.
     */
    private function subscribe(
        User $user,
        string $variantId,
        string $status = Subscription::STATUS_ACTIVE,
        string $type = Subscription::DEFAULT_TYPE,
        ?DateTimeInterface $endsAt = null,
        ?string $pauseMode = null,
    ): Subscription {
        Customer::query()->firstOrCreate([
            'billable_id' => $user->id,
            'billable_type' => $user->getMorphClass(),
        ], [
            'lemon_squeezy_id' => (string) fake()->unique()->randomNumber(8),
        ]);

        $subscription = Subscription::factory()->make([
            'billable_id' => $user->id,
            'billable_type' => $user->getMorphClass(),
            'type' => $type,
            'lemon_squeezy_id' => (string) fake()->unique()->randomNumber(8),
            'status' => $status,
            'variant_id' => $variantId,
            'pause_mode' => $pauseMode,
            'ends_at' => $endsAt,
        ]);

        $subscription->save();

        return $subscription;
    }
}
