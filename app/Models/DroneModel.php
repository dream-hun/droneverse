<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\DroneClass;
use Database\Factories\DroneModelFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * One airframe in the fleet a pilot chooses between.
 *
 * A drone is a performance envelope and a set of dimensions, and the two are
 * not interchangeable: `flight_spec` is what the control loop in
 * resources/js/lib/simulator/physics.ts reads, `airframe_spec` is what the
 * mesh tree in resources/js/components/simulator/drone-model.tsx is built
 * from. Both are mirrored as `DroneFlightSpec` / `DroneAirframeSpec` in
 * resources/js/types/drone.ts, and the array shapes below are the contract
 * between the two halves — the same arrangement `challenges.environment`
 * already has with `EnvironmentConfig`.
 *
 * `restHeight` is the one number both halves need. The physics parks the
 * body centre at it when landed; the landing feet have to reach exactly that
 * far down or the drone hovers above its pad or sinks through it. It lives
 * in `flight_spec` and the geometry derives from it, which is the direction
 * the constants ran in before the fleet had more than one member.
 *
 * @property int $id
 * @property string $uuid
 * @property string $slug
 * @property string $name
 * @property DroneClass $class
 * @property string $summary
 * @property array{cruiseSpeed: float, minCruiseSpeed: float, maxCruiseSpeed: float, maxClimbRate: float, maxDescentRate: float, horizontalAcceleration: float, verticalAcceleration: float, brakingAcceleration: float, maxYawRate: float, yawAcceleration: float, maxTilt: float, spoolSeconds: float, restHeight: float, batteryIdleDrain: float, batteryThrottleDrain: float} $flight_spec
 * @property array{rotors: int, bodyRadius: float, motorReach: float, propRadius: float, bladeLength: float, legLength: float, legReachRatio: float, rotorMaxSpeed: float, livery: string, accent: string} $airframe_spec
 * @property bool $is_default
 * @property int $order
 */
#[Fillable(['slug', 'name', 'class', 'summary', 'flight_spec', 'airframe_spec', 'is_default', 'order'])]
final class DroneModel extends Model
{
    /** @use HasFactory<DroneModelFactory> */
    use HasFactory;

    use HasUuids;

    /**
     * A drone is addressed publicly by its uuid, never by its id.
     *
     * Same reasoning as {@see DronePhoto} and {@see ChallengeRun}: the
     * auto-increment key stays, because the selection on a progress row and
     * the attribution on a run are built on it, but an id is not what leaves
     * the server.
     *
     * The catalogue is also keyed on `slug`, which is what the seeder matches
     * and what a test names a drone by. That is deliberately not the route
     * key: a slug is a name the fleet's authors chose and may revise, while
     * the uuid on a saved selection has to keep meaning the same airframe.
     */
    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    /**
     * The columns Eloquent fills with a generated identifier on insert.
     *
     * Overridden because HasUuids assumes the uuid *is* the primary key.
     * Naming the column here keeps `id` an auto-incrementing integer, which
     * is what `user_challenge_progress.drone_model_id` and
     * `challenge_runs.drone_model_id` point at.
     *
     * @return array<int, string>
     */
    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'class' => DroneClass::class,
            'flight_spec' => 'array',
            'airframe_spec' => 'array',
            'is_default' => 'boolean',
            'order' => 'integer',
        ];
    }

    /**
     * The airframe every pilot flies unless they have chosen otherwise.
     *
     * A scope rather than a `where` repeated at each call site, because the
     * three readers of the default — the cockpit, the landing page's hero,
     * and the suite's own helper — have to agree on what "default" means. The
     * predicate is one column today; it is the kind of thing that grows a
     * second condition when the fleet learns about regions or retirement, and
     * the point of naming it here is that it grows in one place.
     *
     * Does not resolve the row. {@see \App\Actions\ResolveMissionDrone} is
     * still where a missing default is turned into a loud failure rather than
     * a quiet fallback.
     *
     * @param  Builder<DroneModel>  $query
     */
    #[Scope]
    protected function fleetDefault(Builder $query): void
    {
        $query->where('is_default', true);
    }

    /**
     * The fleet in the order the picker lists it.
     *
     * `id` breaks the tie so the order is total. Two drones sharing an
     * `order` would otherwise come back in whatever order the database
     * happened to produce, and a picker whose rows move between requests is
     * a picker a pilot cannot learn.
     *
     * @param  Builder<DroneModel>  $query
     */
    #[Scope]
    protected function inFleetOrder(Builder $query): void
    {
        $query->orderBy('order')->orderBy('id');
    }
}
