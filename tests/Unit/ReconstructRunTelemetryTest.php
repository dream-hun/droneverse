<?php

declare(strict_types=1);

use App\Actions\ReconstructRunTelemetry;
use App\Models\Challenge;

/*
 * The geometry the server reads a submitted flight with, one edge at a time.
 *
 * Like ScoringPolicyTest this touches no database and boots no application: a
 * Challenge is built in memory with the two attributes the reconstruction
 * reads, force-filled because the application-wide Model::unguard() lives in
 * a service provider that never boots here.
 */

/**
 * @param  array<string, mixed>  $environment
 * @param  array<string, mixed>  $criteria
 * @param  array<int, array{0: float|int, 1: float|int, 2: float|int}>  $points  one sample a second
 * @param  array<int, array{x: float, y: float, z: float}>  $photos
 * @return array<string, bool|float|int>
 */
function reconstruct(array $environment, array $criteria, array $points, array $photos = []): array
{
    $challenge = new Challenge()->forceFill([
        'environment' => $environment,
        'success_criteria' => ['waypoints' => [], 'max_time_seconds' => 600, ...$criteria],
    ]);

    $path = [];

    foreach ($points as $second => [$x, $y, $z]) {
        $path[] = ['t' => (float) $second, 'x' => (float) $x, 'y' => (float) $y, 'z' => (float) $z];
    }

    return new ReconstructRunTelemetry()->handle($challenge, ['path' => $path, 'collisions' => 0, 'photos' => $photos]);
}

/**
 * A 4.5 m wide, 3.5 m tall, 8 m long tunnel at the origin, along the z axis.
 *
 * @return array<string, mixed>
 */
function tunnel(): array
{
    return ['carwash' => ['x' => 0, 'z' => 0, 'width' => 4.5, 'height' => 3.5, 'length' => 8]];
}

/**
 * A 4 m cube centred a metre off the ground at the origin.
 *
 * @param  array<string, float|int>  $overrides
 * @return array<string, mixed>
 */
function block(array $overrides = []): array
{
    return ['obstacles' => [['x' => 0, 'y' => 1, 'z' => 0, 'sx' => 4, 'sy' => 4, 'sz' => 4, ...$overrides]]];
}

test('a hover on top of a waypoint reaches it', function (): void {
    $telemetry = reconstruct([], ['waypoints' => [['x' => 0, 'y' => 1, 'z' => 0, 'radius' => 1]]], [
        [0, 1, 0],
        [0, 1, 0],
    ]);

    expect($telemetry['waypointsHit'])->toBe(1);
});

test('a photo more than two metres off the path on any axis is not counted', function (float $dx, float $dy, float $dz): void {
    $telemetry = reconstruct([], [], [[0, 1, 0], [0, 1, 0.5], [0, 1, 1]], [
        ['x' => 0.0, 'y' => 1.0, 'z' => 0.5],
        ['x' => $dx, 'y' => 1.0 + $dy, 'z' => 0.5 + $dz],
    ]);

    expect($telemetry['photosTaken'])->toBe(1);
})->with([
    'east of it' => [3.0, 0.0, 0.0],
    'west of it' => [-3.0, 0.0, 0.0],
    'above it' => [0.0, 3.0, 0.0],
    'below it' => [0.0, -3.0, 0.0],
    'ahead of it' => [0.0, 0.0, 3.0],
    'behind it' => [0.0, 0.0, -3.0],
]);

test('the tunnel flown back to front is a wash', function (): void {
    $telemetry = reconstruct(tunnel(), ['wash_required' => true], [
        [0, 1.5, -6], [0, 1.5, -3.5], [0, 1.5, 0], [0, 1.5, 3.5], [0, 1.5, 6],
    ]);

    expect($telemetry['washed'])->toBeTrue();
});

test('a pass outside the tunnel opening is not a wash', function (float $x, float $y): void {
    $telemetry = reconstruct(tunnel(), ['wash_required' => true], [
        [$x, $y, 6], [$x, $y, 3.5], [$x, $y, 0], [$x, $y, -3.5], [$x, $y, -6],
    ]);

    expect($telemetry['washed'])->toBeFalse();
})->with([
    'under the floor' => [0.0, -1.0],
    'over the roof' => [0.0, 5.0],
    'beside the west wall' => [-3.0, 1.5],
]);

test('a wash mission with no tunnel in its world cannot be washed', function (): void {
    $telemetry = reconstruct([], ['wash_required' => true], [[0, 1.5, 6], [0, 1.5, -6]]);

    expect($telemetry['washed'])->toBeFalse();
});

test('a path straight through a block is a strike whichever way it is flown', function (int $from, int $to): void {
    $telemetry = reconstruct(block(), [], [[$from, 1, 0], [$to, 1, 0]]);

    expect($telemetry['collisions'])->toBe(1);
})->with([
    'eastward' => [-5, 5],
    'westward' => [5, -5],
]);

test('a path that skirts a block without entering it is no strike', function (): void {
    $telemetry = reconstruct(block(), [], [[-5, 1, -5], [5, 1, -5], [5, 1, 5]]);

    expect($telemetry['collisions'])->toBe(0);
});

test('an obstacle thinner than the margin is never a strike', function (): void {
    $telemetry = reconstruct(block(['sx' => 1]), [], [[-5, 1, 0], [5, 1, 0]]);

    expect($telemetry['collisions'])->toBe(0);
});
