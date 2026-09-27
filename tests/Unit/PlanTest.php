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

/**
 * Pro is the top tier, so it grants the lot — and this is the assertion that
 * says so for every case at once. A Feature nobody grants is a row in the
 * pricing page's comparison grid that no plan can tick, which advertises a
 * capability nobody is able to buy; adding a case without listing it on a plan
 * fails here.
 */
test('the top tier grants every feature', function (): void {
    expect(Plan::Pro->features())->toEqualCanonicalizing(Feature::cases());
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

/**
 * Python was dropped rather than deferred. Every paid tier sold it and nothing
 * behind it was ever built, and a second language is a worker sandbox, a grader
 * and a set of mission docs rather than a flag waiting to be flipped. Asserted
 * by absence from the enum: re-adding the case fails here, which is the
 * reminder that it lands in the commit that builds it.
 */
test('no plan sells a language the simulator cannot run', function (): void {
    expect('python_runtime')
        ->not->toBeIn(
            Feature::values(),
            'The simulator runs JavaScript only, so Python is a phase of work rather than a feature flag.',
        );
});

test('catalog coverage ranks pro above starter', function (): void {
    expect(Plan::Starter->covers(Plan::Starter))->toBeTrue()
        ->and(Plan::Starter->covers(Plan::Pro))->toBeFalse()
        ->and(Plan::Pro->covers(Plan::Starter))->toBeTrue()
        ->and(Plan::Pro->covers(Plan::Pro))->toBeTrue();
});

test('only starter is free, and only pro is sold', function (): void {
    expect(Plan::Starter->isPaid())->toBeFalse()
        ->and(Plan::Pro->isPaid())->toBeTrue()
        ->and(Plan::Pro->isSelfServe())->toBeTrue()
        ->and(Plan::Starter->isSelfServe())->toBeFalse();
});

test('only the paid tier can be bought, monthly, yearly or once', function (): void {
    expect(Plan::Pro->variants())->toBe(['monthly', 'yearly', 'lifetime'])
        ->and(Plan::Starter->variants())->toBe([]);
});

/**
 * Lifetime is its own Kelviq plan so a code scoped to the subscription never
 * discounts it, and both grant the catalogue feature that means Pro.
 */
test('pro is sold from two kelviq plans that grant the same catalogue feature', function (): void {
    expect(Plan::Pro->kelviqPlan('monthly'))->toBe('pro')
        ->and(Plan::Pro->kelviqPlan('yearly'))->toBe('pro')
        ->and(Plan::Pro->kelviqPlan('lifetime'))->toBe('pro-lifetime')
        ->and(Plan::Pro->kelviqPlan('weekly'))->toBeNull()
        ->and(Plan::Pro->catalogFeature())->toBe('full-catalog')
        ->and(Plan::Starter->kelviqPlan('monthly'))->toBeNull()
        ->and(Plan::Starter->catalogFeature())->toBeNull();
});

test('a variant maps onto the kelviq charge period that sells it', function (): void {
    expect(Plan::Pro->chargePeriod('monthly'))->toBe('MONTHLY')
        ->and(Plan::Pro->chargePeriod('yearly'))->toBe('YEARLY')
        ->and(Plan::Pro->chargePeriod('lifetime'))->toBe('ONE_TIME')
        ->and(Plan::Pro->chargePeriod('weekly'))->toBeNull()
        ->and(Plan::Pro->chargePeriod('monthly_launch'))->toBeNull()
        ->and(Plan::Starter->chargePeriod('monthly'))->toBeNull();
});

/**
 * Kelviq only sells what has shipped, so a capability still in the hangar has
 * no Kelviq feature and nobody paying through Kelviq can hold it.
 */
test('only shipped capabilities name a kelviq feature', function (): void {
    foreach (Feature::cases() as $feature) {
        expect($feature->kelviqId() !== null)->toBe($feature->isAvailable(), $feature->value);
    }
});

test('display amounts are read from configuration', function (): void {
    config(['plans.amounts.pro' => ['monthly' => 1900, 'yearly' => 19000]]);

    expect(Plan::Pro->amount('monthly'))->toBe(1900)
        ->and(Plan::Pro->amount('yearly'))->toBe(19000)
        ->and(Plan::Pro->amount('weekly'))->toBeNull()
        ->and(Plan::Starter->amount('monthly'))->toBeNull();
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

/**
 * config/plans.php is copy and kelviq.config.ts is what Kelviq charges, and the
 * two are kept in step by hand. This is the check that they were: a price
 * changed in one file and not the other fails here rather than on somebody's
 * card statement.
 */
test('the pricing page quotes what the kelviq catalog charges', function (): void {
    $catalog = (string) file_get_contents(base_path('kelviq.config.ts'));

    foreach (['monthly' => 'MONTHLY', 'yearly' => 'YEARLY', 'lifetime' => 'ONE_TIME'] as $variant => $chargePeriod) {
        $matched = preg_match(sprintf("/chargePeriod: '%s',\\s+priceData: \\{ amount: (\\d+(?:\\.\\d+)?) \\}/", $chargePeriod), $catalog, $match);

        expect($matched)->toBe(1, $chargePeriod.' price missing from kelviq.config.ts')
            ->and((int) round((float) ($match[1] ?? 0) * 100))->toBe(Plan::Pro->amount($variant));
    }
});

/**
 * Every identifier the application asks Kelviq about must be one the catalog
 * defines, or the entitlement it gates can never be granted.
 */
test('every kelviq identifier the application reads is in the catalog', function (): void {
    $catalog = (string) file_get_contents(base_path('kelviq.config.ts'));

    $identifiers = array_filter([
        ...array_map(Plan::Pro->kelviqPlan(...), Plan::Pro->variants()),
        Plan::Pro->catalogFeature(),
        ...array_map(static fn (Feature $feature): ?string => $feature->kelviqId(), Feature::cases()),
    ]);

    foreach ($identifiers as $identifier) {
        expect($catalog)->toContain(sprintf("identifier: '%s'", $identifier));
    }
});
