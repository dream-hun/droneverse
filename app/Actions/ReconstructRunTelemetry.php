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
 *
 * Measuring the path is not by itself enough, because a path is cheap to
 * write: the mission's waypoints and photo targets are shipped to the
 * browser to be rendered, so anyone can read the coordinates off the page
 * and post a handful of samples sitting exactly on them. Two things stop
 * that from being a free perfect run. The submission has to survive
 * {@see \App\Http\Requests\StoreChallengeAttemptRequest}, which rejects a
 * path the simulator could not have sampled; and the flight is then costed
 * against the airframe's own envelope here, so covering ground takes the
 * time it would really take {@see self::flightTimeFloor()} and cutting
 * through a building is charged as the strike it would really be
 * {@see self::floorCollisions()}.
 *
 * That raises forgery from "list the waypoints" to "produce a dense,
 * speed-limited, obstacle-free trajectory" — which is most of the way to
 * simply flying the mission. It is deliberately not a claim that a forged
 * run is impossible: the flight happens in the browser, so a sufficiently
 * determined client can always describe a plausible one. Every check below
 * is therefore built to only ever move a result against the submitter, so
 * that being wrong about an honest pilot is not possible.
 */
final class ReconstructRunTelemetry
{
    /** Drone centre height when it is sitting on the pad, from physics.ts. */
    private const float REST_HEIGHT = 0.15;

    /**
     * Flight envelope, from MAX_CRUISE_SPEED and MAX_CLIMB_RATE in
     * physics.ts plus the largest gust the wind field can add.
     *
     * Used to cost a path in seconds. Set at the envelope rather than at
     * what pilots actually reach — the fastest of the twenty authored
     * missions peaks at 6.3 m/s horizontally and 3.2 m/s vertically — so
     * the floor this produces can never exceed an honest run's own clock.
     */
    private const float MAX_HORIZONTAL_SPEED = 8.5;

    private const float MAX_VERTICAL_SPEED = 3.5;

    /**
     * How far from the flight path a photo may claim to have been taken.
     *
     * The camera is bolted to the airframe: a shot is captured at the
     * drone's own position, during a stabilising hold, and the sampler is
     * recording throughout. Across the twenty authored missions no photo
     * lands further than 6 mm from a path sample — all of it rounding — so
     * two metres is a formality for anyone who flew, and the only thing it
     * rules out is a photo taken somewhere the drone never was.
     */
    private const float PHOTO_CORROBORATION_RADIUS = 2.0;

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

        $elapsed = max(
            $this->elapsedSeconds($path),
            $this->flightTimeFloor($path),
        );
        $photos = $this->photosTakenFromThePath($run['photos'], $path);
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
     * The least time the airframe needs to fly the submitted path.
     *
     * The clock is the client's, and a run that claims to have covered the
     * whole map in a fraction of a second is claiming a drone that does not
     * exist. Each leg is costed at the envelope — the faster of the
     * horizontal and vertical legs decides it, since the two are flown
     * together — and the total becomes a floor under the reported time.
     *
     * Only ever raises the elapsed time, so an honest run keeps its own
     * clock: flying inside the envelope is what the envelope means.
     *
     * @param  array<int, array{t: float, x: float, y: float, z: float}>  $path
     */
    private function flightTimeFloor(array $path): float
    {
        $seconds = 0.0;

        for ($i = 1, $samples = count($path); $i < $samples; $i++) {
            $horizontal = sqrt(
                ($path[$i]['x'] - $path[$i - 1]['x']) ** 2
                + ($path[$i]['z'] - $path[$i - 1]['z']) ** 2
            );
            $vertical = abs($path[$i]['y'] - $path[$i - 1]['y']);

            $seconds += max(
                $horizontal / self::MAX_HORIZONTAL_SPEED,
                $vertical / self::MAX_VERTICAL_SPEED,
            );
        }

        return $seconds;
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
     * The photos the flight path can account for.
     *
     * A photo position is the one claim in a submission with no geometry of
     * its own to answer to — a camera mission with no waypoints could be
     * cleared by posting the target coordinates and nothing else. Requiring
     * the shot to have been taken somewhere the drone demonstrably was ties
     * it back to the path, so the photo quota and the photo targets cost
     * the same flying as everything else.
     *
     * @param  array<int, array{x: float, y: float, z: float}>  $photos
     * @param  array<int, array{t: float, x: float, y: float, z: float}>  $path
     * @return array<int, array{x: float, y: float, z: float}>
     */
    private function photosTakenFromThePath(array $photos, array $path): array
    {
        return array_values(array_filter(
            $photos,
            function (array $photo) use ($path): bool {
                foreach ($path as $sample) {
                    $distance = sqrt(
                        ($photo['x'] - $sample['x']) ** 2
                        + ($photo['y'] - $sample['y']) ** 2
                        + ($photo['z'] - $sample['z']) ** 2
                    );

                    if ($distance <= self::PHOTO_CORROBORATION_RADIUS) {
                        return true;
                    }
                }

                return false;
            },
        ));
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
        $rotation = (float) ($carwash['rotationY'] ?? 0);
        $entryZ = $length / 2 - self::WASH_SENSOR_INSET;

        $enteredFront = false;
        $enteredBack = false;

        foreach ($path as $sample) {
            [$localX, $localZ] = $this->toLocal(
                $sample['x'] - (float) $carwash['x'],
                $sample['z'] - (float) $carwash['z'],
                $rotation,
            );

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
     * Tested against the *segments* between samples, for the same reason
     * {@see self::waypointsHit()} is: a path is a polyline, not a bag of
     * points. Checking only the points would let a submission step over a
     * wall between two samples — the very trick that makes a hand-written
     * path cheap — while an honest run, sampled every few centimetres of
     * travel, cannot tell the difference.
     *
     * @param  array<int, array{t: float, x: float, y: float, z: float}>  $path
     * @param  array<string, mixed>  $environment
     */
    private function floorCollisions(array $path, array $environment): int
    {
        $obstacles = $environment['obstacles'] ?? [];
        $strikes = 0;

        foreach ($obstacles as $obstacle) {
            $half = [
                'x' => ((float) ($obstacle['sx'] ?? ($obstacle['radius'] ?? 0.5) * 2)) / 2 - self::INTRUSION_MARGIN,
                'y' => ((float) ($obstacle['sy'] ?? $obstacle['height'] ?? 1)) / 2 - self::INTRUSION_MARGIN,
                'z' => ((float) ($obstacle['sz'] ?? ($obstacle['radius'] ?? 0.5) * 2)) / 2 - self::INTRUSION_MARGIN,
            ];

            if ($half['x'] <= 0 || $half['y'] <= 0 || $half['z'] <= 0) {
                continue;
            }

            $rotation = (float) ($obstacle['rotationY'] ?? 0);
            $centre = [
                'x' => (float) $obstacle['x'],
                'y' => (float) $obstacle['y'],
                'z' => (float) $obstacle['z'],
            ];

            for ($i = 1, $samples = count($path); $i < $samples; $i++) {
                if ($this->segmentEntersBox(
                    $this->intoBoxFrame($path[$i - 1], $centre, $rotation),
                    $this->intoBoxFrame($path[$i], $centre, $rotation),
                    $half,
                )) {
                    $strikes++;

                    break;
                }
            }
        }

        return $strikes;
    }

    /**
     * A world point in the box's own frame, where it is axis-aligned.
     *
     * @param  array{x: float, y: float, z: float}  $point
     * @param  array{x: float, y: float, z: float}  $centre
     * @return array{x: float, y: float, z: float}
     */
    private function intoBoxFrame(array $point, array $centre, float $rotation): array
    {
        [$localX, $localZ] = $this->toLocal(
            $point['x'] - $centre['x'],
            $point['z'] - $centre['z'],
            $rotation,
        );

        return ['x' => $localX, 'y' => $point['y'] - $centre['y'], 'z' => $localZ];
    }

    /**
     * Whether the segment between two local-frame points enters the box.
     *
     * The slab test: clip the segment against each pair of opposing faces in
     * turn and see whether any of it survives.
     *
     * @param  array{x: float, y: float, z: float}  $from
     * @param  array{x: float, y: float, z: float}  $to
     * @param  array{x: float, y: float, z: float}  $half
     */
    private function segmentEntersBox(array $from, array $to, array $half): bool
    {
        $enter = 0.0;
        $exit = 1.0;

        foreach (['x', 'y', 'z'] as $axis) {
            $direction = $to[$axis] - $from[$axis];

            if (abs($direction) < 1e-9) {
                // Parallel to this pair of faces: either always between them
                // or never.
                if (abs($from[$axis]) > $half[$axis]) {
                    return false;
                }

                continue;
            }

            $first = (-$half[$axis] - $from[$axis]) / $direction;
            $second = ($half[$axis] - $from[$axis]) / $direction;

            $enter = max($enter, min($first, $second));
            $exit = min($exit, max($first, $second));

            if ($enter > $exit) {
                return false;
            }
        }

        return true;
    }

    /**
     * Rotate a world-space offset into the frame of an object turned
     * `rotationY` about the Y axis.
     *
     * The inverse of the transform three.js applies to `rotation-y`, which
     * is what places these objects in the scene. Shared so the wash tunnel
     * and the obstacles cannot end up disagreeing about which way is which
     * — they did while each carried its own copy, and a tunnel turned by
     * anything other than a right angle failed an honest pass through it.
     *
     * @return array{0: float, 1: float}
     */
    private function toLocal(float $dx, float $dz, float $rotation): array
    {
        return [
            $dx * cos($rotation) - $dz * sin($rotation),
            $dx * sin($rotation) + $dz * cos($rotation),
        ];
    }
}
