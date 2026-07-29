<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Enums\Feature;
use App\Enums\Plan;
use Tests\TestCase;

final class PlanTest extends TestCase
{
    public function test_starter_grants_no_gated_features(): void
    {
        $this->assertSame([], Plan::Starter->features());

        foreach (Feature::cases() as $feature) {
            $this->assertFalse(Plan::Starter->hasFeature($feature));
        }
    }

    public function test_pro_grants_every_feature_the_comparison_table_sells(): void
    {
        $expected = [
            Feature::PythonRuntime,
            Feature::MissionBuilder,
            Feature::DroneConfigEditor,
            Feature::PremiumCertificates,
            Feature::AdvancedAnalytics,
            Feature::DownloadableProjects,
            Feature::PrioritySupport,
            Feature::BetaAccess,
        ];

        foreach ($expected as $feature) {
            $this->assertTrue(Plan::Pro->hasFeature($feature), $feature->value);
        }

        $this->assertFalse(Plan::Pro->hasFeature(Feature::TeamManagement));
        $this->assertFalse(Plan::Pro->hasFeature(Feature::ClassroomTools));
        $this->assertFalse(Plan::Pro->hasFeature(Feature::ApiAccess));
        $this->assertFalse(Plan::Pro->hasFeature(Feature::Sso));
    }

    /**
     * The AI assistant was dropped rather than deferred, so no plan may grant
     * it and no comparison row may sell it. Asserted by absence from the enum:
     * re-adding the case fails here, which is the reminder that it arrives with
     * a credit ledger or not at all.
     */
    public function test_no_plan_sells_a_metered_capability(): void
    {
        $this->assertNotContains(
            'ai_assistant',
            Feature::values(),
            'An AI assistant carries a per-call cost, so it needs a credit ledger before it needs a feature flag.',
        );
    }

    public function test_team_adds_classroom_features_on_top_of_pro(): void
    {
        $this->assertTrue(Plan::Team->hasFeature(Feature::TeamManagement));
        $this->assertTrue(Plan::Team->hasFeature(Feature::ClassroomTools));
        $this->assertTrue(Plan::Team->hasFeature(Feature::PythonRuntime));

        $this->assertFalse(Plan::Team->hasFeature(Feature::ApiAccess));
        $this->assertFalse(Plan::Team->hasFeature(Feature::Sso));
    }

    public function test_enterprise_grants_every_feature(): void
    {
        $this->assertEqualsCanonicalizing(Feature::cases(), Plan::Enterprise->features());
    }

    public function test_catalog_coverage_ranks_the_paid_tiers_above_starter(): void
    {
        $this->assertTrue(Plan::Starter->covers(Plan::Starter));
        $this->assertFalse(Plan::Starter->covers(Plan::Pro));

        $this->assertTrue(Plan::Pro->covers(Plan::Starter));
        $this->assertTrue(Plan::Team->covers(Plan::Pro));
        $this->assertTrue(Plan::Enterprise->covers(Plan::Team));
        $this->assertFalse(Plan::Pro->covers(Plan::Team));
    }

    public function test_only_starter_is_free(): void
    {
        $this->assertFalse(Plan::Starter->isPaid());

        foreach ([Plan::Pro, Plan::Team, Plan::Enterprise] as $plan) {
            $this->assertTrue($plan->isPaid(), $plan->value);
        }
    }

    public function test_team_and_enterprise_are_not_self_serve_until_their_features_exist(): void
    {
        $this->assertTrue(Plan::Pro->isSelfServe());

        $this->assertFalse(Plan::Team->isSelfServe());
        $this->assertFalse(Plan::Enterprise->isSelfServe());
        $this->assertFalse(Plan::Starter->isSelfServe());
    }

    public function test_price_ids_are_read_from_configuration(): void
    {
        config(['plans.prices.pro' => [
            'monthly' => 'pri_pro_monthly',
            'yearly' => 'pri_pro_yearly',
            'monthly_launch' => null,
            'yearly_launch' => '',
        ]]);

        $this->assertSame('pri_pro_monthly', Plan::Pro->priceId('monthly'));
        $this->assertSame(
            ['monthly' => 'pri_pro_monthly', 'yearly' => 'pri_pro_yearly'],
            Plan::Pro->priceIds(),
        );
    }

    public function test_unconfigured_variants_resolve_to_null_rather_than_a_default(): void
    {
        config(['plans.prices.pro' => ['monthly' => null, 'yearly' => '']]);

        $this->assertNull(Plan::Pro->priceId('monthly'));
        $this->assertNull(Plan::Pro->priceId('yearly'));
        $this->assertNull(Plan::Pro->priceId('does_not_exist'));
        $this->assertNull(Plan::Starter->priceId('monthly'));
    }

    public function test_a_price_id_maps_back_to_the_plan_that_sells_it(): void
    {
        config(['plans.prices' => [
            'pro' => ['monthly' => 'pri_pro_monthly', 'monthly_launch' => 'pri_pro_monthly_launch'],
            'team' => ['monthly' => 'pri_team_monthly'],
        ]]);

        $this->assertSame(Plan::Pro, Plan::fromPriceId('pri_pro_monthly'));
        $this->assertSame(Plan::Pro, Plan::fromPriceId('pri_pro_monthly_launch'));
        $this->assertSame(Plan::Team, Plan::fromPriceId('pri_team_monthly'));
    }

    public function test_an_unrecognised_price_id_grants_nothing(): void
    {
        config(['plans.prices' => ['pro' => ['monthly' => 'pri_pro_monthly']]]);

        $this->assertNull(Plan::fromPriceId('pri_retired_beta_plan'));
        $this->assertNull(Plan::fromPriceId(null));
        $this->assertNull(Plan::fromPriceId(''));
    }

    public function test_unconfigured_price_ids_do_not_collide_on_null(): void
    {
        config(['plans.prices' => [
            'pro' => ['monthly' => null],
            'team' => ['monthly' => null],
        ]]);

        $this->assertNull(Plan::fromPriceId(null));
        $this->assertNull(Plan::fromPriceId('pri_anything'));
    }

    public function test_a_price_id_maps_back_to_the_billing_period_it_sells(): void
    {
        config(['plans.prices.pro' => [
            'monthly' => 'pri_pro_monthly',
            'yearly' => 'pri_pro_yearly',
        ]]);

        $this->assertSame('yearly', Plan::Pro->variantFor('pri_pro_yearly'));
        $this->assertNull(Plan::Pro->variantFor('pri_team_monthly'));
        $this->assertNull(Plan::Pro->variantFor(null));
        $this->assertNull(Plan::Pro->variantFor(''));
    }

    /**
     * Which periods a plan offers is a fact about the plan, not about whether
     * this environment happens to have priced it — an unpriced Pro still sells
     * monthly and yearly, it just cannot be bought.
     */
    public function test_only_the_subscription_tiers_offer_a_billing_period(): void
    {
        config(['plans.prices' => []]);

        $this->assertSame(['monthly', 'yearly'], Plan::Pro->variants());
        $this->assertSame(['monthly', 'yearly'], Plan::Team->variants());
        $this->assertSame([], Plan::Starter->variants());
        $this->assertSame([], Plan::Enterprise->variants());
    }

    public function test_display_amounts_are_read_from_configuration(): void
    {
        config(['plans.amounts.pro' => ['monthly' => 1900, 'yearly' => 19000]]);

        $this->assertSame(1900, Plan::Pro->amount('monthly'));
        $this->assertSame(19000, Plan::Pro->amount('yearly'));
        $this->assertNull(Plan::Pro->amount('weekly'));
        $this->assertNull(Plan::Starter->amount('monthly'));
    }

    /**
     * Every variant a plan offers must be priced, or the pricing page quotes a
     * blank where a number belongs.
     */
    public function test_every_offered_billing_period_carries_a_display_amount(): void
    {
        foreach (Plan::cases() as $plan) {
            foreach ($plan->variants() as $variant) {
                $this->assertIsInt(
                    $plan->amount($variant),
                    sprintf('%s.%s is offered but has no amount in config/plans.php.', $plan->value, $variant),
                );
            }
        }
    }
}
