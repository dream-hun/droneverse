<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\DroneModel;
use Illuminate\Database\Seeder;
use JsonException;
use RuntimeException;

/**
 * The fleet, as authored.
 *
 * Every number in `drone-fleet.json` was a module constant in
 * resources/js/lib/simulator/physics.ts and
 * resources/js/lib/simulator/airframe.ts, and the HX-6 Surveyor's numbers are
 * still exactly those constants. That is the point of it: the Surveyor is the
 * default airframe, every mission in the catalogue was authored and balanced
 * against it, and a pilot who never opens the picker — which is every pilot
 * whose plan does not reach it — flies precisely the drone they always flew.
 * The other four are the choice.
 *
 * The choice is meant to cost something in both directions. The Cadet is
 * easier to place precisely and will time out on a mission the Surveyor
 * finishes comfortably; the Vector will finish anything on the clock and
 * gives back the margin that makes a 3 m slalom gate survivable; the
 * Freighter is stable enough to hold a photo target in frame and wide enough
 * that threading it through anything is a decision. None of them is a
 * straight upgrade, which is what keeps the picker a configuration rather
 * than a difficulty slider.
 *
 * The fleet lives in a JSON file rather than in a PHP array here because it
 * has two readers in two languages. The geometry is not free-form — adjacent
 * rotor discs must not intersect, the landing feet must reach the height the
 * physics parks the body at, and the whole airframe must still fit through
 * the narrowest gate the catalogue is authored with — and those invariants
 * are checked in resources/js/lib/simulator/airframe.test.ts, because the
 * code that derives the geometry from a spec is TypeScript. A copy of the
 * fleet kept there for the test to read would be a copy that could disagree
 * with the one actually seeded, and it would disagree silently: the test
 * would go on passing about drones nobody flies. One file, both readers.
 */
final class DroneSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * Matched on `slug` so re-running revises the fleet in place. A drone's
     * uuid is what a pilot's saved selection points at, so it has to survive
     * a reseed — `updateOrCreate` keeps it, and the seeder never writes one.
     */
    public function run(): void
    {
        foreach ($this->fleet() as $order => $drone) {
            DroneModel::query()->updateOrCreate(
                ['slug' => $drone['slug']],
                [
                    'name' => $drone['name'],
                    'class' => $drone['class'],
                    'summary' => $drone['summary'],
                    'flight_spec' => $drone['flight_spec'],
                    'airframe_spec' => $drone['airframe_spec'],
                    'is_default' => $drone['is_default'],
                    'order' => $order,
                ],
            );
        }
    }

    /**
     * The authored fleet, in the order the picker lists it.
     *
     * @return array<int, array{slug: string, name: string, class: string, summary: string, is_default: bool, flight_spec: array<string, float>, airframe_spec: array<string, float|int|string>}>
     *
     * @throws JsonException
     */
    private function fleet(): array
    {
        $path = database_path('seeders/drone-fleet.json');
        $contents = file_get_contents($path);

        if ($contents === false) {
            throw new RuntimeException(sprintf('The drone fleet could not be read from [%s].', $path));
        }

        /** @var array<int, array{slug: string, name: string, class: string, summary: string, is_default: bool, flight_spec: array<string, float>, airframe_spec: array<string, float|int|string>}> $fleet */
        $fleet = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);

        return $fleet;
    }
}
