<?php

declare(strict_types=1);

use App\Enums\Feature;
use App\Enums\Plan;
use Tests\TestCase;

uses(TestCase::class);

test('starter grants no gated features', function (): void {
    $this->assertSame([], Plan::Starter->features());

    foreach (Feature::cases() as $feature) {
        $this->assertFalse(Plan::Starter->hasFeature($feature));
    }
});

test('pro grants every feature the comparison table sells', function (): void {
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
});

/**
 * The AI assistant was dropped rather than deferred, so no plan may grant
 * it and no comparison row may sell it. Asserted by absence from the enum:
 * re-adding the case fails here, which is the reminder that it arrives with
 * a credit ledger or not at all.
 */
test('no plan sells a metered capability', function (): void {
    $this->assertNotContains(
        'ai_assistant',
        Feature::values(),
        'An AI assistant carries a per-call cost, so it needs a credit ledger before it needs a feature flag.',
    );
});

test('team adds classroom features on top of pro', function (): void {
    $this->assertTrue(Plan::Team->hasFeature(Feature::TeamManagement));
    $this->assertTrue(Plan::Team->hasFeature(Feature::ClassroomTools));
    $this->assertTrue(Plan::Team->hasFeature(Feature::PythonRuntime));

    $this->assertFalse(Plan::Team->hasFeature(Feature::ApiAccess));
    $this->assertFalse(Plan::Team->hasFeature(Feature::Sso));
});

test('enterprise grants every feature', function (): void {
    $this->assertEqualsCanonicalizing(Feature::cases(), Plan::Enterprise->features());
});

test('catalog coverage ranks the paid tiers above starter', function (): void {
    $this->assertTrue(Plan::Starter->covers(Plan::Starter));
    $this->assertFalse(Plan::Starter->covers(Plan::Pro));

    $this->assertTrue(Plan::Pro->covers(Plan::Starter));
    $this->assertTrue(Plan::Team->covers(Plan::Pro));
    $this->assertTrue(Plan::Enterprise->covers(Plan::Team));
    $this->assertFalse(Plan::Pro->covers(Plan::Team));
});

test('only starter is free', function (): void {
    $this->assertFalse(Plan::Starter->isPaid());

    foreach ([Plan::Pro, Plan::Team, Plan::Enterprise] as $plan) {
        $this->assertTrue($plan->isPaid(), $plan->value);
    }
});

/**
 * A classroom of ten is a card payment, so Team sells itself alongside Pro.
 * Enterprise is the only tier a button cannot buy, and not because of what
 * has shipped: a private deployment and an SLA are terms, and there is no
 * amount to charge until they are agreed.
 */
test('every tier but enterprise and the free one sells itself', function (): void {
    $this->assertTrue(Plan::Pro->isSelfServe());
    $this->assertTrue(Plan::Team->isSelfServe());

    $this->assertFalse(Plan::Enterprise->isSelfServe());
    $this->assertFalse(Plan::Starter->isSelfServe());
});

/**
 * Only changing an existing subscription needs a product ID, so an
 * environment that has never configured one still sells every tier.
 */
test('product ids are read from configuration', function (): void {
    config(['plans.products' => [
        'pro' => 'prod_pro',
        'team' => '',
    ]]);

    $this->assertSame('prod_pro', Plan::Pro->productId());
    $this->assertNull(Plan::Team->productId());
    $this->assertNull(Plan::Starter->productId());
});

test('price ids are read from configuration', function (): void {
    config(['plans.prices.pro' => [
        'monthly' => 'var_pro_monthly',
        'yearly' => 'var_pro_yearly',
        'monthly_launch' => null,
        'yearly_launch' => '',
    ]]);

    $this->assertSame('var_pro_monthly', Plan::Pro->priceId('monthly'));
    $this->assertSame(
        ['monthly' => 'var_pro_monthly', 'yearly' => 'var_pro_yearly'],
        Plan::Pro->priceIds(),
    );
});

test('unconfigured variants resolve to null rather than a default', function (): void {
    config(['plans.prices.pro' => ['monthly' => null, 'yearly' => '']]);

    $this->assertNull(Plan::Pro->priceId('monthly'));
    $this->assertNull(Plan::Pro->priceId('yearly'));
    $this->assertNull(Plan::Pro->priceId('does_not_exist'));
    $this->assertNull(Plan::Starter->priceId('monthly'));
});

test('a price id maps back to the plan that sells it', function (): void {
    config(['plans.prices' => [
        'pro' => ['monthly' => 'var_pro_monthly', 'monthly_launch' => 'var_pro_monthly_launch'],
        'team' => ['monthly' => 'var_team_monthly'],
    ]]);

    $this->assertSame(Plan::Pro, Plan::fromPriceId('var_pro_monthly'));
    $this->assertSame(Plan::Pro, Plan::fromPriceId('var_pro_monthly_launch'));
    $this->assertSame(Plan::Team, Plan::fromPriceId('var_team_monthly'));
});

test('an unrecognised price id grants nothing', function (): void {
    config(['plans.prices' => ['pro' => ['monthly' => 'var_pro_monthly']]]);

    $this->assertNull(Plan::fromPriceId('var_retired_beta_plan'));
    $this->assertNull(Plan::fromPriceId(null));
    $this->assertNull(Plan::fromPriceId(''));
});

/**
 * config/plans.php requires price IDs to be unique across plans and nothing
 * enforces it, so the same variant ID under two tiers is one paste into one
 * `.env`. Answering with the first match would decide it by the order the
 * cases are declared in — Pro before Team, for no reason anybody chose — and
 * hand Pro to every Team subscriber without a word. There is no honest
 * answer to which of two plans one ID sells, so it grants neither.
 */
test('a price id claimed by two plans grants neither', function (): void {
    config(['plans.prices' => [
        'pro' => ['monthly' => 'var_shared_by_mistake'],
        'team' => ['monthly' => 'var_shared_by_mistake', 'yearly' => 'var_team_yearly'],
    ]]);

    $this->assertNull(Plan::fromPriceId('var_shared_by_mistake'));

    // The slip is contained: every other ID still resolves.
    $this->assertSame(Plan::Team, Plan::fromPriceId('var_team_yearly'));
});

/**
 * The same ID twice within one plan is not ambiguous — both periods sell the
 * same tier, so the tier is still the answer. Only variantFor() has to pick,
 * and it says so itself.
 */
test('a price id repeated within one plan still grants that plan', function (): void {
    config(['plans.prices.pro' => [
        'monthly' => 'var_pro_everything',
        'yearly' => 'var_pro_everything',
    ]]);

    $this->assertSame(Plan::Pro, Plan::fromPriceId('var_pro_everything'));
});

test('unconfigured price ids do not collide on null', function (): void {
    config(['plans.prices' => [
        'pro' => ['monthly' => null],
        'team' => ['monthly' => null],
    ]]);

    $this->assertNull(Plan::fromPriceId(null));
    $this->assertNull(Plan::fromPriceId('var_anything'));
});

test('a price id maps back to the billing period it sells', function (): void {
    config(['plans.prices.pro' => [
        'monthly' => 'var_pro_monthly',
        'yearly' => 'var_pro_yearly',
    ]]);

    $this->assertSame('yearly', Plan::Pro->variantFor('var_pro_yearly'));
    $this->assertNull(Plan::Pro->variantFor('var_team_monthly'));
    $this->assertNull(Plan::Pro->variantFor(null));
    $this->assertNull(Plan::Pro->variantFor(''));
});

/**
 * Which periods a plan offers is a fact about the plan, not about whether
 * this environment happens to have priced it — an unpriced Pro still sells
 * monthly and yearly, it just cannot be bought.
 */
test('only the subscription tiers offer a billing period', function (): void {
    config(['plans.prices' => []]);

    $this->assertSame(['monthly', 'yearly'], Plan::Pro->variants());
    $this->assertSame(['monthly', 'yearly'], Plan::Team->variants());
    $this->assertSame([], Plan::Starter->variants());
    $this->assertSame([], Plan::Enterprise->variants());
});

test('display amounts are read from configuration', function (): void {
    config(['plans.amounts.pro' => ['monthly' => 1900, 'yearly' => 19000]]);

    $this->assertSame(1900, Plan::Pro->amount('monthly'));
    $this->assertSame(19000, Plan::Pro->amount('yearly'));
    $this->assertNull(Plan::Pro->amount('weekly'));
    $this->assertNull(Plan::Starter->amount('monthly'));
});

/**
 * Every variant a plan offers must be priced, or the pricing page quotes a
 * blank where a number belongs.
 */
test('every offered billing period carries a display amount', function (): void {
    foreach (Plan::cases() as $plan) {
        foreach ($plan->variants() as $variant) {
            $this->assertIsInt(
                $plan->amount($variant),
                sprintf('%s.%s is offered but has no amount in config/plans.php.', $plan->value, $variant),
            );
        }
    }
});
