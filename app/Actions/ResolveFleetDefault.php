<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\DroneModel;
use RuntimeException;

/**
 * The airframe every pilot flies unless they have chosen otherwise.
 *
 * Its own action rather than a private helper on {@see ResolveMissionDrone}
 * because two pages resolve the default and neither is a mission: the cockpit
 * falls back to it for every pilot who has not picked, and the landing page
 * turns it in the hero so a visitor is shown the drone they would be handed on
 * signing up. An invariant enforced on one of those and not the other is not
 * an invariant, it is a page that happens to check.
 */
final readonly class ResolveFleetDefault
{
    /**
     * Resolve the one drone the fleet marks as its default.
     *
     * Refuses both ways it can be wrong, because both are the same mistake —
     * a catalogue that does not answer the question — and neither is
     * survivable by guessing. No default is an unseeded or half-seeded
     * database. Two defaults is a bad `drone-fleet.json` edit, and it is the
     * more dangerous of the pair: the query still returns a drone, so the
     * simulator would go on working while flying pilots in an airframe chosen
     * by row order, on missions whose balance depends on it being the
     * Surveyor. Ordering the fleet differently would then silently rebalance
     * the catalogue.
     *
     * Two rows are fetched rather than counted so the check and the answer
     * come from one query: anything other than exactly one row is a fault,
     * and past two the number stops mattering.
     */
    public function handle(): DroneModel
    {
        $defaults = DroneModel::query()->fleetDefault()->take(2)->get();

        if ($defaults->count() !== 1) {
            throw new RuntimeException($defaults->isEmpty()
                ? 'The fleet names no default drone. Run the DroneSeeder.'
                : 'The fleet names more than one default drone. Exactly one row in drone_models may set is_default.');
        }

        return $defaults->sole();
    }
}
