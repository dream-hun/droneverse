<?php

declare(strict_types=1);

use App\Enums\Feature;
use App\Enums\Plan;
use Tests\TestCase;

uses(TestCase::class);

test('starter grants no gated features', function (): void {
    expect(Plan::Starter->features())->toBe([]);

    foreach (Feature::cases() as $feature) {
        expect(Plan::Starter->hasFeature($feature))->toBeFalse();
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
        expect(Plan::Pro->hasFeature($feature))->toBeTrue($feature->value);
    }

    expect(Plan::Pro->hasFeature(Feature::TeamManagement))->toBeFalse();
    expect(Plan::Pro->hasFeature(Feature::ClassroomTools))->toBeFalse();
    expect(Plan::Pro->hasFeature(Feature::ApiAccess))->toBeFalse();
    expect(Plan::Pro->hasFeature(Feature::Sso))->toBeFalse();
});

/**
 * The AI assistant was dropped rather than deferred, so no plan may grant
 * it and no comparison row may sell it. Asserted by absence from the enum:
 * re-adding the case fails here, which is the reminder that it arrives with
 * a credit ledger or not at all.
 */
test('no plan sells a metered capability', function (): void {
    expect('ai_assistant')
        ->not->toBeIn(
            Feature::values(),
            'An AI assistant carries a per-call cost, so it needs a credit ledger before it needs a feature flag.',
        );
});

test('team adds classroom features on top of pro', function (): void {
    expect(Plan::Team->hasFeature(Feature::TeamManagement))->toBeTrue();
    expect(Plan::Team->hasFeature(Feature::ClassroomTools))->toBeTrue();
    expect(Plan::Team->hasFeature(Feature::PythonRuntime))->toBeTrue();

    expect(Plan::Team->hasFeature(Feature::ApiAccess))->toBeFalse();
    expect(Plan::Team->hasFeature(Feature::Sso))->toBeFalse();
});

test('enterprise grants every feature', function (): void {
    expect(Plan::Enterprise->features())->toEqualCanonicalizing(Feature::cases());
});

test('catalog coverage ranks the paid tiers above starter', function (): void {
    expect(Plan::Starter->covers(Plan::Starter))->toBeTrue();
    expect(Plan::Starter->covers(Plan::Pro))->toBeFalse();

    expect(Plan::Pro->covers(Plan::Starter))->toBeTrue();
    expect(Plan::Team->covers(Plan::Pro))->toBeTrue();
    expect(Plan::Enterprise->covers(Plan::Team))->toBeTrue();
    expect(Plan::Pro->covers(Plan::Team))->toBeFalse();
});

test('only starter is free', function (): void {
    expect(Plan::Starter->isPaid())->toBeFalse();

    foreach ([Plan::Pro, Plan::Team, Plan::Enterprise] as $plan) {
        expect($plan->isPaid())->toBeTrue($plan->value);
    }
});

/**
 * A classroom of ten is a card payment, so Team sells itself alongside Pro.
 * Enterprise is the only tier a button cannot buy, and not because of what
 * has shipped: a private deployment and an SLA are terms, and there is no
 * amount to charge until they are agreed.
 */
test('every tier but enterprise and the free one sells itself', function (): void {
    expect(Plan::Pro->isSelfServe())->toBeTrue();
    expect(Plan::Team->isSelfServe())->toBeTrue();

    expect(Plan::Enterprise->isSelfServe())->toBeFalse();
    expect(Plan::Starter->isSelfServe())->toBeFalse();
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

    expect(Plan::Pro->productId())->toBe('prod_pro');
    expect(Plan::Team->productId())->toBeNull();
    expect(Plan::Starter->productId())->toBeNull();
});

test('price ids are read from configuration', function (): void {
    config(['plans.prices.pro' => [
        'monthly' => 'var_pro_monthly',
        'yearly' => 'var_pro_yearly',
        'monthly_launch' => null,
        'yearly_launch' => '',
    ]]);

    expect(Plan::Pro->priceId('monthly'))->toBe('var_pro_monthly');
    expect(Plan::Pro->priceIds())->toBe(['monthly' => 'var_pro_monthly', 'yearly' => 'var_pro_yearly']);
});

test('unconfigured variants resolve to null rather than a default', function (): void {
    config(['plans.prices.pro' => ['monthly' => null, 'yearly' => '']]);

    expect(Plan::Pro->priceId('monthly'))->toBeNull();
    expect(Plan::Pro->priceId('yearly'))->toBeNull();
    expect(Plan::Pro->priceId('does_not_exist'))->toBeNull();
    expect(Plan::Starter->priceId('monthly'))->toBeNull();
});

test('a price id maps back to the plan that sells it', function (): void {
    config(['plans.prices' => [
        'pro' => ['monthly' => 'var_pro_monthly', 'monthly_launch' => 'var_pro_monthly_launch'],
        'team' => ['monthly' => 'var_team_monthly'],
    ]]);

    expect(Plan::fromPriceId('var_pro_monthly'))->toBe(Plan::Pro);
    expect(Plan::fromPriceId('var_pro_monthly_launch'))->toBe(Plan::Pro);
    expect(Plan::fromPriceId('var_team_monthly'))->toBe(Plan::Team);
});

test('an unrecognised price id grants nothing', function (): void {
    config(['plans.prices' => ['pro' => ['monthly' => 'var_pro_monthly']]]);

    expect(Plan::fromPriceId('var_retired_beta_plan'))->toBeNull();
    expect(Plan::fromPriceId(null))->toBeNull();
    expect(Plan::fromPriceId(''))->toBeNull();
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

    expect(Plan::fromPriceId('var_shared_by_mistake'))->toBeNull();

    // The slip is contained: every other ID still resolves.
    expect(Plan::fromPriceId('var_team_yearly'))->toBe(Plan::Team);
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

    expect(Plan::fromPriceId('var_pro_everything'))->toBe(Plan::Pro);
});

test('unconfigured price ids do not collide on null', function (): void {
    config(['plans.prices' => [
        'pro' => ['monthly' => null],
        'team' => ['monthly' => null],
    ]]);

    expect(Plan::fromPriceId(null))->toBeNull();
    expect(Plan::fromPriceId('var_anything'))->toBeNull();
});

test('a price id maps back to the billing period it sells', function (): void {
    config(['plans.prices.pro' => [
        'monthly' => 'var_pro_monthly',
        'yearly' => 'var_pro_yearly',
    ]]);

    expect(Plan::Pro->variantFor('var_pro_yearly'))->toBe('yearly');
    expect(Plan::Pro->variantFor('var_team_monthly'))->toBeNull();
    expect(Plan::Pro->variantFor(null))->toBeNull();
    expect(Plan::Pro->variantFor(''))->toBeNull();
});

/**
 * Which periods a plan offers is a fact about the plan, not about whether
 * this environment happens to have priced it — an unpriced Pro still sells
 * monthly and yearly, it just cannot be bought.
 */
test('only the subscription tiers offer a billing period', function (): void {
    config(['plans.prices' => []]);

    expect(Plan::Pro->variants())->toBe(['monthly', 'yearly']);
    expect(Plan::Team->variants())->toBe(['monthly', 'yearly']);
    expect(Plan::Starter->variants())->toBe([]);
    expect(Plan::Enterprise->variants())->toBe([]);
});

test('display amounts are read from configuration', function (): void {
    config(['plans.amounts.pro' => ['monthly' => 1900, 'yearly' => 19000]]);

    expect(Plan::Pro->amount('monthly'))->toBe(1900);
    expect(Plan::Pro->amount('yearly'))->toBe(19000);
    expect(Plan::Pro->amount('weekly'))->toBeNull();
    expect(Plan::Starter->amount('monthly'))->toBeNull();
});

/**
 * Every variant a plan offers must be priced, or the pricing page quotes a
 * blank where a number belongs.
 */
test('every offered billing period carries a display amount', function (): void {
    foreach (Plan::cases() as $plan) {
        foreach ($plan->variants() as $variant) {
            expect($plan->amount($variant))
                ->toBeInt(sprintf('%s.%s is offered but has no amount in config/plans.php.', $plan->value, $variant));
        }
    }
});
