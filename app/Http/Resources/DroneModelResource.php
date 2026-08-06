<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\DroneModel;
use Illuminate\Support\Collection;

/**
 * An airframe as the cockpit needs it: enough to pick it, and enough to fly it.
 *
 * Both specs travel in full, because the client is what flies the drone —
 * the control loop and the mesh tree both live in the browser, and neither
 * has a second source for these numbers. Nothing here is a secret: the fleet
 * is a catalogue, and a pilot reading its constants out of the page props
 * learns exactly what the picker already tells them.
 *
 * The `id` a client sees is the drone's uuid, which is the only identifier
 * it can act on — `challenges.drone.update` binds by route key. Mirrors the
 * `DroneModelSummary` type in resources/js/types/drone.ts.
 */
final class DroneModelResource
{
    /**
     * @param  Collection<int, DroneModel>  $drones
     * @return array<int, array{id: string, slug: string, name: string, class: string, classLabel: string, summary: string, isDefault: bool, flight: array<string, float>, airframe: array<string, float|int|string>}>
     */
    public static function collection(Collection $drones): array
    {
        return $drones->map(fn (DroneModel $drone): array => self::one($drone))->all();
    }

    /**
     * @return array{id: string, slug: string, name: string, class: string, classLabel: string, summary: string, isDefault: bool, flight: array<string, float>, airframe: array<string, float|int|string>}
     */
    public static function one(DroneModel $drone): array
    {
        return [
            'id' => $drone->uuid,
            'slug' => $drone->slug,
            'name' => $drone->name,
            'class' => $drone->class->value,
            'classLabel' => $drone->class->label(),
            'summary' => $drone->summary,
            'isDefault' => $drone->is_default,
            'flight' => $drone->flight_spec,
            'airframe' => $drone->airframe_spec,
        ];
    }
}
