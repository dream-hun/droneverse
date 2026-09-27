<?php

declare(strict_types=1);

use App\Models\User;
use App\Queries\KelviqEntitlements;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

test('an environment with no kelviq key grants nothing and calls nobody', function (): void {
    Http::preventStrayRequests();
    $user = User::factory()->create();

    expect(resolve(KelviqEntitlements::class)->for($user))->toBe([]);

    Http::assertNothingSent();
});

test('an answer is cached for the fresh ttl', function (): void {
    $user = User::factory()->create();
    fakeKelviq([$user->uuid => ['full-catalog', 'advanced-analytics']]);
    $entitlements = resolve(KelviqEntitlements::class);

    expect($entitlements->for($user))->toBe(['full-catalog', 'advanced-analytics'])
        ->and($entitlements->hasAccess($user, 'advanced-analytics'))->toBeTrue()
        ->and($entitlements->hasAccess($user, 'beta-access'))->toBeFalse();

    Http::assertSentCount(1);
});

test('forgetting a customer makes the next read ask kelviq again', function (): void {
    $user = User::factory()->create();
    fakeKelviq([$user->uuid => ['full-catalog']]);
    $entitlements = resolve(KelviqEntitlements::class);

    $entitlements->for($user);
    $entitlements->forget($user->uuid);
    $entitlements->for($user);

    Http::assertSentCount(2);
});

/**
 * The SDK's aggregation: one entry per feature per subscription, granted if any
 * of them grants it.
 */
test('entries are aggregated by feature, granted if any entry grants it', function (): void {
    $user = User::factory()->create();
    fakeKelviq(responses: ['edge.sandboxapi.kelviq.com/api/v1/entitlements*' => Http::response(['entitlements' => [
        ['featureId' => 'full-catalog', 'hasAccess' => false],
        ['featureId' => 'full-catalog', 'hasAccess' => true],
        ['featureId' => 'full-catalog', 'hasAccess' => true],
        ['featureId' => 'beta-access', 'hasAccess' => false],
        ['featureId' => 42, 'hasAccess' => true],
        ['hasAccess' => true],
        ['featureId' => 'priority-support', 'hasAccess' => 'yes'],
    ]])]);

    expect(resolve(KelviqEntitlements::class)->for($user))->toBe(['full-catalog']);
});

test('a customer kelviq has never heard of holds nothing, and that is cached too', function (): void {
    $user = User::factory()->create();
    fakeKelviq(responses: ['edge.sandboxapi.kelviq.com/api/v1/entitlements*' => Http::response(['detail' => 'Not found.'], 404)]);
    $entitlements = resolve(KelviqEntitlements::class);

    expect($entitlements->for($user))->toBe([])
        ->and($entitlements->for($user))->toBe([]);

    Http::assertSentCount(1);
});

test('while kelviq is failing the last good answer is served', function (): void {
    $user = User::factory()->create();
    fakeKelviq(responses: ['edge.sandboxapi.kelviq.com/api/v1/entitlements*' => Http::sequence()
        ->push(['entitlements' => [['featureId' => 'full-catalog', 'hasAccess' => true]]])
        ->push('unavailable', 503)]);
    $entitlements = resolve(KelviqEntitlements::class);

    expect($entitlements->for($user))->toBe(['full-catalog']);

    Log::shouldReceive('warning')->once()->withArgs(fn (string $message): bool => str_contains($message, 'serving the last good answer'));
    $entitlements->forget($user->uuid);

    expect($entitlements->for($user))->toBe(['full-catalog']);
});

/**
 * Failing closed: Starter until Kelviq answers. The fallback is cached for the
 * fresh TTL so an outage costs one failed call a minute per pilot, not one per
 * page.
 */
test('with no last good answer a failure grants nothing, and is not retried every page', function (): void {
    $user = User::factory()->create();
    Log::shouldReceive('warning')->once()->withArgs(fn (string $message): bool => str_contains($message, 'failing closed'));
    fakeKelviq(responses: ['edge.sandboxapi.kelviq.com/api/v1/entitlements*' => Http::failedConnection()]);
    $entitlements = resolve(KelviqEntitlements::class);

    expect($entitlements->for($user))->toBe([])
        ->and($entitlements->for($user))->toBe([]);

    Http::assertSentCount(1);
});

test('a rejected key fails closed like any other error', function (): void {
    $user = User::factory()->create();
    fakeKelviq(responses: ['edge.sandboxapi.kelviq.com/api/v1/entitlements*' => Http::response(['detail' => 'Invalid token.'], 401)]);

    expect(resolve(KelviqEntitlements::class)->for($user))->toBe([]);
});

test('only feature identifiers are trusted out of the cache', function (): void {
    $user = User::factory()->create();
    fakeKelviq();
    Cache::put('kelviq:entitlements:'.$user->uuid, ['full-catalog', 7, null], 60);

    expect(resolve(KelviqEntitlements::class)->for($user))->toBe(['full-catalog']);
});

test('a misconfigured ttl falls back to the sdk default', function (): void {
    $user = User::factory()->create();
    fakeKelviq([$user->uuid => ['full-catalog']]);
    config(['kelviq.cache_ttl' => 'a minute', 'kelviq.stale_ttl' => 0]);

    expect(resolve(KelviqEntitlements::class)->for($user))->toBe(['full-catalog'])
        ->and(Cache::get('kelviq:entitlements:'.$user->uuid.':stale'))->toBe(['full-catalog']);
});

test('a connection error thrown by the client is a failure like any other', function (): void {
    $user = User::factory()->create();
    fakeKelviq(responses: ['edge.sandboxapi.kelviq.com/api/v1/entitlements*' => fn () => throw new ConnectionException('timed out')]);

    expect(resolve(KelviqEntitlements::class)->for($user))->toBe([]);
});
