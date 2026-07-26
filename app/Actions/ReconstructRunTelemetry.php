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
 *
 * Every measurement here is a sweep over the whole path, and the path is
 * the largest thing a request carries — up to
 * {@see \App\Http\Requests\StoreChallengeAttemptRequest} four thousand
 * samples. So the path is unpacked once into flat coordinate lists
 * {@see self::handle()} and the geometry below reads those, rather than
 * re-walking arrays of associative samples per obstacle, per waypoint and
 * per photo. Nothing about what is measured changes; it is the same
 * geometry over a cheaper representation of the same points.
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

        // One pass over the submitted samples, after which nothing below
        // touches the associative form again. The values arrive already cast
        // by {@see \App\Http\Requests\StoreChallengeAttemptRequest::run()},
        // which is the contract this method's signature states.
        $xs = array_column($run['path'], 'x');
        $ys = array_column($run['path'], 'y');
        $zs = array_column($run['path'], 'z');
        $ts = array_column($run['path'], 't');
        $samples = count($xs);

        $waypoints = $this->waypointsFrom($criteria);
        $photoTargets = $this->photoTargetsFrom($criteria);
        $minPhotos = (int) ($criteria['min_photos'] ?? 0);
        $washRequired = ($criteria['wash_required'] ?? false) === true;
        $maxSeconds = (float) $criteria['max_time_seconds'];

        $elapsed = max(
            $samples === 0 ? 0.0 : (float) $ts[$samples - 1],
            $this->flightTimeFloor($xs, $ys, $zs, $samples),
        );
        $photos = $this->photosTakenFromThePath($run['photos'], $xs, $ys, $zs, $samples);
        $photoTargetsHit = $this->photoTargetsHit($photos, $photoTargets);

        return [
            'waypointsHit' => $this->waypointsHit($xs, $ys, $zs, $samples, $waypoints),
            'waypointsTotal' => count($waypoints),
            'collisions' => max(
                $run['collisions'],
                $this->floorCollisions($xs, $ys, $zs, $samples, $environment),
            ),
            'maxAltitude' => $ys === [] ? 0.0 : max($ys),
            'landed' => $samples !== 0 && $ys[$samples - 1] <= self::REST_HEIGHT + self::LANDING_EPSILON,
            'elapsedSeconds' => $elapsed,
            'timedOut' => $elapsed >= $maxSeconds,
            'photosTaken' => count($photos),
            'photoTargetsHit' => $photoTargetsHit,
            'photoTargetsTotal' => count($photoTargets),
            'photosMissing' => max(0, $minPhotos - count($photos)),
            'washRequired' => $washRequired,
            'washed' => $washRequired && $this->washed($xs, $ys, $zs, $samples, $environment),
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
     * @param  array<int, float>  $xs
     * @param  array<int, float>  $ys
     * @param  array<int, float>  $zs
     */
    private function flightTimeFloor(array $xs, array $ys, array $zs, int $samples): float
    {
        if ($samples < 2) {
            return 0.0;
        }

        $seconds = 0.0;
        $previousX = $xs[0];
        $previousY = $ys[0];
        $previousZ = $zs[0];

        for ($i = 1; $i < $samples; $i++) {
            $x = $xs[$i];
            $y = $ys[$i];
            $z = $zs[$i];

            $dx = $x - $previousX;
            $dz = $z - $previousZ;

            $seconds += max(
                sqrt($dx * $dx + $dz * $dz) / self::MAX_HORIZONTAL_SPEED,
                abs($y - $previousY) / self::MAX_VERTICAL_SPEED,
            );

            $previousX = $x;
            $previousY = $y;
            $previousZ = $z;
        }

        return $seconds;
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
     * @param  array<int, float>  $xs
     * @param  array<int, float>  $ys
     * @param  array<int, float>  $zs
     * @param  array<int, array{x: float, y: float, z: float, radius: float}>  $waypoints
     */
    private function waypointsHit(array $xs, array $ys, array $zs, int $samples, array $waypoints): int
    {
        $total = count($waypoints);

        if ($total === 0 || $samples < 2) {
            return 0;
        }

        // Squared radii, so the segment test never has to take a root.
        $radii = [];

        foreach ($waypoints as $index => $waypoint) {
            $radii[$index] = $waypoint['radius'] * $waypoint['radius'];
        }

        $next = 0;
        $fromX = $xs[0];
        $fromY = $ys[0];
        $fromZ = $zs[0];

        for ($i = 1; $i < $samples && $next < $total; $i++) {
            $toX = $xs[$i];
            $toY = $ys[$i];
            $toZ = $zs[$i];

            $dx = $toX - $fromX;
            $dy = $toY - $fromY;
            $dz = $toZ - $fromZ;
            $lengthSquared = $dx * $dx + $dy * $dy + $dz * $dz;

            while ($next < $total) {
                $waypoint = $waypoints[$next];

                // Closest approach along the segment to the waypoint centre.
                $t = $lengthSquared <= 1e-9
                    ? 0.0
                    : max(0.0, min(1.0, (
                        ($waypoint['x'] - $fromX) * $dx
                        + ($waypoint['y'] - $fromY) * $dy
                        + ($waypoint['z'] - $fromZ) * $dz
                    ) / $lengthSquared));

                $ex = $fromX + $t * $dx - $waypoint['x'];
                $ey = $fromY + $t * $dy - $waypoint['y'];
                $ez = $fromZ + $t * $dz - $waypoint['z'];

                if ($ex * $ex + $ey * $ey + $ez * $ez > $radii[$next]) {
                    break;
                }

                $next++;
            }

            $fromX = $toX;
            $fromY = $toY;
            $fromZ = $toZ;
        }

        return $next;
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
     * @param  array<int, float>  $xs
     * @param  array<int, float>  $ys
     * @param  array<int, float>  $zs
     * @return array<int, array{x: float, y: float, z: float}>
     */
    private function photosTakenFromThePath(array $photos, array $xs, array $ys, array $zs, int $samples): array
    {
        $radius = self::PHOTO_CORROBORATION_RADIUS;
        $radiusSquared = $radius * $radius;
        $corroborated = [];

        foreach ($photos as $photo) {
            $px = (float) $photo['x'];
            $py = (float) $photo['y'];
            $pz = (float) $photo['z'];

            for ($i = 0; $i < $samples; $i++) {
                // Cheapest rejection first: a sample further than the radius
                // on any one axis cannot be within it in three.
                $dx = $xs[$i] - $px;

                if ($dx > $radius || $dx < -$radius) {
                    continue;
                }

                $dy = $ys[$i] - $py;

                if ($dy > $radius || $dy < -$radius) {
                    continue;
                }

                $dz = $zs[$i] - $pz;

                if ($dz > $radius || $dz < -$radius) {
                    continue;
                }

                if ($dx * $dx + $dy * $dy + $dz * $dz <= $radiusSquared) {
                    $corroborated[] = ['x' => $px, 'y' => $py, 'z' => $pz];

                    break;
                }
            }
        }

        return $corroborated;
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
            $radiusSquared = $target['radius'] * $target['radius'];

            foreach ($photos as $photo) {
                $dx = $photo['x'] - $target['x'];
                $dz = $photo['z'] - $target['z'];

                if ($dx * $dx + $dz * $dz <= $radiusSquared) {
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
     * @param  array<int, float>  $xs
     * @param  array<int, float>  $ys
     * @param  array<int, float>  $zs
     * @param  array<string, mixed>  $environment
     */
    private function washed(array $xs, array $ys, array $zs, int $samples, array $environment): bool
    {
        $carwash = $environment['carwash'] ?? null;

        if (! is_array($carwash)) {
            return false;
        }

        $halfWidth = ((float) ($carwash['width'] ?? self::DEFAULT_WASH_WIDTH)) / 2;
        $height = (float) ($carwash['height'] ?? self::DEFAULT_WASH_HEIGHT);
        $length = (float) ($carwash['length'] ?? self::DEFAULT_WASH_LENGTH);
        $rotation = (float) ($carwash['rotationY'] ?? 0);
        $entryZ = $length / 2 - self::WASH_SENSOR_INSET;
        $inset = self::WASH_SENSOR_INSET;

        // The tunnel does not move, so its rotation is resolved once rather
        // than once per sample.
        $cos = cos($rotation);
        $sin = sin($rotation);
        $originX = (float) $carwash['x'];
        $originZ = (float) $carwash['z'];

        $enteredFront = false;
        $enteredBack = false;

        for ($i = 0; $i < $samples; $i++) {
            $y = $ys[$i];

            if ($y < 0 || $y > $height) {
                continue;
            }

            $dx = $xs[$i] - $originX;
            $dz = $zs[$i] - $originZ;

            $localX = $dx * $cos - $dz * $sin;

            if ($localX > $halfWidth || $localX < -$halfWidth) {
                continue;
            }

            // A sample-rate-tolerant band around each sensor plane.
            $localZ = $dx * $sin + $dz * $cos;

            if (abs($localZ - $entryZ) <= $inset) {
                $enteredFront = true;

                if ($enteredBack) {
                    return true;
                }
            }

            if (abs($localZ + $entryZ) <= $inset) {
                $enteredBack = true;

                if ($enteredFront) {
                    return true;
                }
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
     * This is the most expensive thing the class does — every obstacle
     * against every segment — so each obstacle is first tested against the
     * bounding box of the whole path. A mission's obstacles are spread
     * across the map and a flight visits a corner of it, so most obstacles
     * are settled by that one comparison and never see the sweep at all.
     * The rejection is exact rather than heuristic: an obstacle whose
     * world-space box misses the path's own box cannot be entered by any
     * segment of it.
     *
     * @param  array<int, float>  $xs
     * @param  array<int, float>  $ys
     * @param  array<int, float>  $zs
     * @param  array<string, mixed>  $environment
     */
    private function floorCollisions(array $xs, array $ys, array $zs, int $samples, array $environment): int
    {
        $obstacles = $environment['obstacles'] ?? [];

        if ($obstacles === [] || $xs === [] || $ys === [] || $zs === []) {
            return 0;
        }

        $pathMinX = min($xs);
        $pathMaxX = max($xs);
        $pathMinY = min($ys);
        $pathMaxY = max($ys);
        $pathMinZ = min($zs);
        $pathMaxZ = max($zs);

        $strikes = 0;

        foreach ($obstacles as $obstacle) {
            $halfX = ((float) ($obstacle['sx'] ?? ($obstacle['radius'] ?? 0.5) * 2)) / 2 - self::INTRUSION_MARGIN;
            $halfY = ((float) ($obstacle['sy'] ?? $obstacle['height'] ?? 1)) / 2 - self::INTRUSION_MARGIN;
            $halfZ = ((float) ($obstacle['sz'] ?? ($obstacle['radius'] ?? 0.5) * 2)) / 2 - self::INTRUSION_MARGIN;

            if ($halfX <= 0 || $halfY <= 0 || $halfZ <= 0) {
                continue;
            }

            $rotation = (float) ($obstacle['rotationY'] ?? 0);
            $cos = cos($rotation);
            $sin = sin($rotation);
            $centreX = (float) $obstacle['x'];
            $centreY = (float) $obstacle['y'];
            $centreZ = (float) $obstacle['z'];

            // Half-extents of the Y-rotated box measured on the world axes:
            // exact, so nothing that could be struck is discarded here.
            $worldHalfX = abs($halfX * $cos) + abs($halfZ * $sin);
            $worldHalfZ = abs($halfX * $sin) + abs($halfZ * $cos);

            $minX = $centreX - $worldHalfX;
            $maxX = $centreX + $worldHalfX;
            $minY = $centreY - $halfY;
            $maxY = $centreY + $halfY;
            $minZ = $centreZ - $worldHalfZ;
            $maxZ = $centreZ + $worldHalfZ;

            if (
                $maxX < $pathMinX || $minX > $pathMaxX
                || $maxY < $pathMinY || $minY > $pathMaxY
                || $maxZ < $pathMinZ || $minZ > $pathMaxZ
            ) {
                continue;
            }

            $previousX = $xs[0];
            $previousY = $ys[0];
            $previousZ = $zs[0];

            for ($i = 1; $i < $samples; $i++) {
                $x = $xs[$i];
                $y = $ys[$i];
                $z = $zs[$i];

                // A segment lying wholly beyond one face of the world box
                // cannot reach the rotated box inside it. Almost every
                // segment of a real flight is settled right here, which is
                // what keeps the rotation and the slab test off the hot
                // path.
                if (
                    ($x > $maxX && $previousX > $maxX) || ($x < $minX && $previousX < $minX)
                    || ($y > $maxY && $previousY > $maxY) || ($y < $minY && $previousY < $minY)
                    || ($z > $maxZ && $previousZ > $maxZ) || ($z < $minZ && $previousZ < $minZ)
                ) {
                    $previousX = $x;
                    $previousY = $y;
                    $previousZ = $z;

                    continue;
                }

                $dx = $previousX - $centreX;
                $dz = $previousZ - $centreZ;
                $dx2 = $x - $centreX;
                $dz2 = $z - $centreZ;

                if ($this->segmentEntersBox(
                    $dx * $cos - $dz * $sin,
                    $previousY - $centreY,
                    $dx * $sin + $dz * $cos,
                    $dx2 * $cos - $dz2 * $sin,
                    $y - $centreY,
                    $dx2 * $sin + $dz2 * $cos,
                    $halfX, $halfY, $halfZ,
                )) {
                    $strikes++;

                    break;
                }

                $previousX = $x;
                $previousY = $y;
                $previousZ = $z;
            }
        }

        return $strikes;
    }

    /**
     * Whether the segment between two local-frame points enters the box.
     *
     * The slab test: clip the segment against each pair of opposing faces in
     * turn and see whether any of it survives. Written out per axis rather
     * than looped, because this runs once per path segment per obstacle and
     * the loop's own bookkeeping cost more than the arithmetic in it.
     */
    private function segmentEntersBox(
        float $fromX, float $fromY, float $fromZ,
        float $toX, float $toY, float $toZ,
        float $halfX, float $halfY, float $halfZ,
    ): bool {
        $enter = 0.0;
        $exit = 1.0;

        $direction = $toX - $fromX;

        if ($direction > -1e-9 && $direction < 1e-9) {
            // Parallel to this pair of faces: either always between them or
            // never.
            if ($fromX > $halfX || $fromX < -$halfX) {
                return false;
            }
        } else {
            $first = (-$halfX - $fromX) / $direction;
            $second = ($halfX - $fromX) / $direction;

            if ($first > $second) {
                [$first, $second] = [$second, $first];
            }

            if ($first > $enter) {
                $enter = $first;
            }

            if ($second < $exit) {
                $exit = $second;
            }

            if ($enter > $exit) {
                return false;
            }
        }

        $direction = $toY - $fromY;

        if ($direction > -1e-9 && $direction < 1e-9) {
            if ($fromY > $halfY || $fromY < -$halfY) {
                return false;
            }
        } else {
            $first = (-$halfY - $fromY) / $direction;
            $second = ($halfY - $fromY) / $direction;

            if ($first > $second) {
                [$first, $second] = [$second, $first];
            }

            if ($first > $enter) {
                $enter = $first;
            }

            if ($second < $exit) {
                $exit = $second;
            }

            if ($enter > $exit) {
                return false;
            }
        }

        $direction = $toZ - $fromZ;

        if ($direction > -1e-9 && $direction < 1e-9) {
            return $fromZ <= $halfZ && $fromZ >= -$halfZ;
        }

        $first = (-$halfZ - $fromZ) / $direction;
        $second = ($halfZ - $fromZ) / $direction;

        if ($first > $second) {
            [$first, $second] = [$second, $first];
        }

        if ($first > $enter) {
            $enter = $first;
        }

        if ($second < $exit) {
            $exit = $second;
        }

        return $enter <= $exit;
    }
}
