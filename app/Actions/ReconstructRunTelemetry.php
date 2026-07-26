<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\Challenge;

/**
 * Rebuilds what a run achieved from the path the drone actually flew.
 *
 * The browser is the only thing that can simulate the flight — the drone is
 * a Rapier rigid body and re-running it here would not reproduce the same
 * trajectory — but it does not get to *assert* its own result. It reports
 * where the airframe went; every objective is then measured against the
 * mission's own geometry on this side, so claiming a waypoint requires
 * having flown through it.
 *
 * What still comes from the client, and why:
 *
 * - `collisions`, which needs the physics engine's contact resolution to
 *   detect. Under-reporting it is the remaining gap; see
 *   {@see self::floorCollisions()} for the sanity check that limits it.
 * - the photo positions, which are checked against the mission's photo
 *   targets here, so a fabricated one still has to be in the right place.
 */
final class ReconstructRunTelemetry
{
    /** Drone centre height when it is sitting on the pad, from physics.ts. */
    private const float REST_HEIGHT = 0.15;

    /** How close to rest height counts as "on the ground". */
    private const float LANDING_EPSILON = 0.35;

    /** Half-depth of the wash tunnel's traversal sensors, from city-props.tsx. */
    private const float WASH_SENSOR_INSET = 0.5;

    private const float DEFAULT_WASH_WIDTH = 4.5;

    private const float DEFAULT_WASH_HEIGHT = 3.5;

    private const float DEFAULT_WASH_LENGTH = 8.0;

    /**
     * Margin subtracted from an obstacle's own half-extents before a path
     * sample inside it is treated as a strike.
     *
     * Deliberately generous: the penalty this feeds can only ever be raised,
     * never lowered, so a false positive costs an honest pilot a star. Only
     * a path well inside solid geometry should trip it.
     */
    private const float INTRUSION_MARGIN = 0.6;

    /**
     * @param  array{path: array<int, array{t: float, x: float, y: float, z: float}>, collisions: int, photos: array<int, array{x: float, y: float, z: float}>}  $run
     * @return array{waypointsHit: int, waypointsTotal: int, collisions: int, maxAltitude: float, landed: bool, elapsedSeconds: float, timedOut: bool, photosTaken: int, photoTargetsHit: int, photoTargetsTotal: int, photosMissing: int, washRequired: bool, washed: bool}
     */
    public function handle(Challenge $challenge, array $run): array
    {
        $criteria = $challenge->success_criteria;
        $environment = $challenge->environment;
        $path = $run['path'];

        $waypoints = $this->waypointsFrom($criteria);
        $photoTargets = $this->photoTargetsFrom($criteria);
        $minPhotos = (int) ($criteria['min_photos'] ?? 0);
        $washRequired = ($criteria['wash_required'] ?? false) === true;
        $maxSeconds = (float) $criteria['max_time_seconds'];

        $elapsed = $this->elapsedSeconds($path);
        $photos = $run['photos'];
        $photoTargetsHit = $this->photoTargetsHit($photos, $photoTargets);

        return [
            'waypointsHit' => $this->waypointsHit($path, $waypoints),
            'waypointsTotal' => count($waypoints),
            'collisions' => max(
                $run['collisions'],
                $this->floorCollisions($path, $environment),
            ),
            'maxAltitude' => $this->maxAltitude($path),
            'landed' => $this->landed($path),
            'elapsedSeconds' => $elapsed,
            'timedOut' => $elapsed >= $maxSeconds,
            'photosTaken' => count($photos),
            'photoTargetsHit' => $photoTargetsHit,
            'photoTargetsTotal' => count($photoTargets),
            'photosMissing' => max(0, $minPhotos - count($photos)),
            'washRequired' => $washRequired,
            'washed' => $washRequired && $this->washed($path, $environment),
        ];
    }

    /**
     * @param  array<string, mixed>  $criteria
     * @return array<int, array{x: float, y: float, z: float, radius: float}>
     */
    private function waypointsFrom(array $criteria): array
    {
        return array_values(array_map(
            fn (array $waypoint): array => [
                'x' => (float) $waypoint['x'],
                'y' => (float) $waypoint['y'],
                'z' => (float) $waypoint['z'],
                'radius' => (float) $waypoint['radius'],
            ],
            $criteria['waypoints'] ?? [],
        ));
    }

    /**
     * @param  array<string, mixed>  $criteria
     * @return array<int, array{x: float, z: float, radius: float}>
     */
    private function photoTargetsFrom(array $criteria): array
    {
        return array_values(array_map(
            fn (array $target): array => [
                'x' => (float) $target['x'],
                'z' => (float) $target['z'],
                'radius' => (float) $target['radius'],
            ],
            $criteria['photo_targets'] ?? [],
        ));
    }

    /**
     * @param  array<int, array{t: float, x: float, y: float, z: float}>  $path
     */
    private function elapsedSeconds(array $path): float
    {
        return $path === [] ? 0.0 : (float) end($path)['t'];
    }

    /**
     * @param  array<int, array{t: float, x: float, y: float, z: float}>  $path
     */
    private function maxAltitude(array $path): float
    {
        return $path === [] ? 0.0 : max(array_column($path, 'y'));
    }

    /**
     * Whether the drone finished the run on the ground.
     *
     * The simulator's own `landed` flag is set when a `land` command runs to
     * completion, which always leaves the airframe resting on the pad — so
     * the final sample's height answers the same question without taking the
     * client's word for it.
     *
     * @param  array<int, array{t: float, x: float, y: float, z: float}>  $path
     */
    private function landed(array $path): bool
    {
        if ($path === []) {
            return false;
        }

        return (float) end($path)['y'] <= self::REST_HEIGHT + self::LANDING_EPSILON;
    }

    /**
     * Waypoints reached, in order.
     *
     * Measured against each *segment* of the path rather than its sampled
     * points. The path arrives at a fixed sample rate, so a drone crossing a
     * small waypoint at cruise speed can easily straddle it between two
     * samples; testing the closest approach along the segment finds the
     * crossing that a point-by-point test would miss and wrongly fail an
     * honest pilot for.
     *
     * @param  array<int, array{t: float, x: float, y: float, z: float}>  $path
     * @param  array<int, array{x: float, y: float, z: float, radius: float}>  $waypoints
     */
    private function waypointsHit(array $path, array $waypoints): int
    {
        if ($waypoints === [] || count($path) < 2) {
            return 0;
        }

        $next = 0;

        for ($i = 1, $samples = count($path); $i < $samples; $i++) {
            while ($next < count($waypoints)) {
                $waypoint = $waypoints[$next];

                $distance = $this->segmentPointDistance(
                    $path[$i - 1],
                    $path[$i],
                    $waypoint,
                );

                if ($distance > $waypoint['radius']) {
                    break;
                }

                $next++;
            }
        }

        return $next;
    }

    /**
     * Shortest distance from a point to the segment between two samples.
     *
     * @param  array{x: float, y: float, z: float}  $from
     * @param  array{x: float, y: float, z: float}  $to
     * @param  array{x: float, y: float, z: float}  $point
     */
    private function segmentPointDistance(array $from, array $to, array $point): float
    {
        $dx = $to['x'] - $from['x'];
        $dy = $to['y'] - $from['y'];
        $dz = $to['z'] - $from['z'];

        $lengthSquared = $dx ** 2 + $dy ** 2 + $dz ** 2;

        $t = $lengthSquared <= 1e-9
            ? 0.0
            : max(0.0, min(1.0, (
                ($point['x'] - $from['x']) * $dx
                + ($point['y'] - $from['y']) * $dy
                + ($point['z'] - $from['z']) * $dz
            ) / $lengthSquared));

        return sqrt(
            ($from['x'] + $t * $dx - $point['x']) ** 2
            + ($from['y'] + $t * $dy - $point['y']) ** 2
            + ($from['z'] + $t * $dz - $point['z']) ** 2
        );
    }

    /**
     * Photo targets covered by at least one captured frame.
     *
     * Matched on the ground plane only, exactly as the browser grades it: a
     * target is a place on the map, not a point in the air.
     *
     * @param  array<int, array{x: float, y: float, z: float}>  $photos
     * @param  array<int, array{x: float, z: float, radius: float}>  $targets
     */
    private function photoTargetsHit(array $photos, array $targets): int
    {
        $hit = 0;

        foreach ($targets as $target) {
            foreach ($photos as $photo) {
                $distance = sqrt(
                    ($photo['x'] - $target['x']) ** 2
                    + ($photo['z'] - $target['z']) ** 2
                );

                if ($distance <= $target['radius']) {
                    $hit++;

                    break;
                }
            }
        }

        return $hit;
    }

    /**
     * Whether the path made a full pass through the wash tunnel.
     *
     * In the browser this is two sensor volumes just inside either mouth. The
     * same test works here: rotate each sample into the tunnel's local frame
     * and check that the path was inside the opening at both ends.
     *
     * @param  array<int, array{t: float, x: float, y: float, z: float}>  $path
     * @param  array<string, mixed>  $environment
     */
    private function washed(array $path, array $environment): bool
    {
        $carwash = $environment['carwash'] ?? null;

        if (! is_array($carwash)) {
            return false;
        }

        $width = (float) ($carwash['width'] ?? self::DEFAULT_WASH_WIDTH);
        $height = (float) ($carwash['height'] ?? self::DEFAULT_WASH_HEIGHT);
        $length = (float) ($carwash['length'] ?? self::DEFAULT_WASH_LENGTH);
        $rotation = -(float) ($carwash['rotationY'] ?? 0);
        $entryZ = $length / 2 - self::WASH_SENSOR_INSET;

        $enteredFront = false;
        $enteredBack = false;

        foreach ($path as $sample) {
            $dx = $sample['x'] - (float) $carwash['x'];
            $dz = $sample['z'] - (float) $carwash['z'];

            $localX = $dx * cos($rotation) - $dz * sin($rotation);
            $localZ = $dx * sin($rotation) + $dz * cos($rotation);

            $insideOpening = abs($localX) <= $width / 2
                && $sample['y'] >= 0
                && $sample['y'] <= $height;

            if (! $insideOpening) {
                continue;
            }

            // A sample-rate-tolerant band around each sensor plane.
            if (abs($localZ - $entryZ) <= self::WASH_SENSOR_INSET) {
                $enteredFront = true;
            }

            if (abs($localZ + $entryZ) <= self::WASH_SENSOR_INSET) {
                $enteredBack = true;
            }
        }

        return $enteredFront && $enteredBack;
    }

    /**
     * The fewest collisions the path can honestly have produced.
     *
     * Contact resolution lives in the physics engine, so a run that claims
     * zero collisions cannot be fully disproved here. What can be checked is
     * whether the drone flew through the middle of something solid: each
     * obstacle it passed clean through is at least one strike it did not
     * report. The margin keeps this to unambiguous cases, because the result
     * only ever raises the penalty.
     *
     * @param  array<int, array{t: float, x: float, y: float, z: float}>  $path
     * @param  array<string, mixed>  $environment
     */
    private function floorCollisions(array $path, array $environment): int
    {
        $obstacles = $environment['obstacles'] ?? [];
        $strikes = 0;

        foreach ($obstacles as $obstacle) {
            $halfX = ((float) ($obstacle['sx'] ?? ($obstacle['radius'] ?? 0.5) * 2)) / 2 - self::INTRUSION_MARGIN;
            $halfY = ((float) ($obstacle['sy'] ?? $obstacle['height'] ?? 1)) / 2 - self::INTRUSION_MARGIN;
            $halfZ = ((float) ($obstacle['sz'] ?? ($obstacle['radius'] ?? 0.5) * 2)) / 2 - self::INTRUSION_MARGIN;

            if ($halfX <= 0 || $halfY <= 0 || $halfZ <= 0) {
                continue;
            }

            $rotation = -(float) ($obstacle['rotationY'] ?? 0);

            foreach ($path as $sample) {
                $dx = $sample['x'] - (float) $obstacle['x'];
                $dz = $sample['z'] - (float) $obstacle['z'];
                $localX = $dx * cos($rotation) - $dz * sin($rotation);
                $localZ = $dx * sin($rotation) + $dz * cos($rotation);

                if (
                    abs($localX) <= $halfX
                    && abs($sample['y'] - (float) $obstacle['y']) <= $halfY
                    && abs($localZ) <= $halfZ
                ) {
                    $strikes++;

                    break;
                }
            }
        }

        return $strikes;
    }
}
