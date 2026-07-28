<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Actions\ResolvePlanForUser;
use App\Enums\Feature;
use App\Enums\Plan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Route;
use Laravel\Paddle\Subscription;
use Tests\TestCase;

final class EntitlementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['plans.prices' => [
            'pro' => [
                'monthly' => 'pri_pro_monthly',
                'yearly' => 'pri_pro_yearly',
                'monthly_launch' => 'pri_pro_monthly_launch',
            ],
            'team' => ['monthly' => 'pri_team_monthly'],
        ]]);
    }

    public function test_a_user_with_nothing_resolves_to_starter(): void
    {
        $user = User::factory()->create();

        $this->assertSame(Plan::Starter, $this->resolve($user));
    }

    public function test_a_guest_resolves_to_starter(): void
    {
        $this->assertSame(Plan::Starter, app(ResolvePlanForUser::class)->handle(null));
    }

    public function test_a_plan_override_resolves_to_that_plan(): void
    {
        $user = User::factory()->onPlan(Plan::Pro)->create();

        $this->assertSame(Plan::Pro, $this->resolve($user));
    }

    public function test_a_plan_override_outranks_an_active_subscription(): void
    {
        $user = User::factory()->onPlan(Plan::Enterprise)->create();
        $this->subscribe($user, 'pri_pro_monthly');

        $this->assertSame(Plan::Enterprise, $this->resolve($user));
    }

    public function test_an_override_naming_a_retired_plan_falls_through_instead_of_throwing(): void
    {
        $user = User::factory()->create(['plan_override' => 'platinum']);

        $this->assertSame(Plan::Starter, $this->resolve($user));
    }

    public function test_an_active_subscription_resolves_through_its_price_id(): void
    {
        $user = User::factory()->create();
        $this->subscribe($user, 'pri_pro_monthly');

        $this->assertSame(Plan::Pro, $this->resolve($user));
    }

    public function test_a_trialing_subscription_still_grants_its_plan(): void
    {
        $user = User::factory()->create();
        $this->subscribe($user, 'pri_pro_yearly', Subscription::STATUS_TRIALING);

        $this->assertSame(Plan::Pro, $this->resolve($user));
    }

    public function test_a_cancelled_subscription_grants_nothing(): void
    {
        $user = User::factory()->create();
        $this->subscribe($user, 'pri_pro_monthly', Subscription::STATUS_CANCELED);

        $this->assertSame(Plan::Starter, $this->resolve($user));
    }

    public function test_the_most_generous_of_several_subscriptions_wins(): void
    {
        $user = User::factory()->create();
        $this->subscribe($user, 'pri_pro_monthly');
        $this->subscribe($user, 'pri_team_monthly', type: 'classroom');

        $this->assertSame(Plan::Team, $this->resolve($user));
    }

    public function test_a_subscription_to_an_unrecognised_price_id_grants_nothing(): void
    {
        $user = User::factory()->create();
        $this->subscribe($user, 'pri_some_retired_experiment');

        $this->assertSame(Plan::Starter, $this->resolve($user));
    }

    public function test_one_users_subscription_does_not_leak_to_another(): void
    {
        $subscriber = User::factory()->create();
        $this->subscribe($subscriber, 'pri_pro_monthly');

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
                    && ! $features->contains('team_management')));
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
        return app(ResolvePlanForUser::class)->handle($user->fresh());
    }

    /**
     * Give the user a Paddle subscription to a single price.
     */
    private function subscribe(
        User $user,
        string $priceId,
        string $status = Subscription::STATUS_ACTIVE,
        string $type = 'default',
    ): Subscription {
        $subscription = Subscription::create([
            'billable_id' => $user->id,
            'billable_type' => $user->getMorphClass(),
            'type' => $type,
            'paddle_id' => 'sub_'.uniqid(),
            'status' => $status,
        ]);

        $subscription->items()->create([
            'product_id' => 'pro_test',
            'price_id' => $priceId,
            'status' => $status,
            'quantity' => 1,
        ]);

        return $subscription;
    }
}
