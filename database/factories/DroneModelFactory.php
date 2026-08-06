<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\DroneClass;
use App\Models\DroneModel;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DroneModel>
 */
final class DroneModelFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * The stock Surveyor's numbers, because a test that builds a drone
     * without saying which one wants a drone that flies the way every
     * mission was authored against. Tests that care about the difference
     * between two airframes set the fields they care about.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = sprintf('%s-%d %s', mb_strtoupper(fake()->lexify('??')), fake()->numberBetween(2, 9), ucfirst(fake()->unique()->word()));

        return [
            'slug' => (string) str($name)->slug(),
            'name' => $name,
            'class' => fake()->randomElement(DroneClass::cases()),
            'summary' => fake()->sentence(),
            'is_default' => false,
            'order' => fake()->numberBetween(0, 10),
            'flight_spec' => [
                'cruiseSpeed' => 5.0,
                'minCruiseSpeed' => 1.0,
                'maxCruiseSpeed' => 8.0,
                'maxClimbRate' => 3.0,
                'maxDescentRate' => 2.5,
                'horizontalAcceleration' => 4.0,
                'verticalAcceleration' => 3.0,
                'brakingAcceleration' => 3.2,
                'maxYawRate' => M_PI,
                'yawAcceleration' => M_PI * 2,
                'maxTilt' => 0.38,
                'spoolSeconds' => 0.6,
                'restHeight' => 0.15,
                'batteryIdleDrain' => 0.04,
                'batteryThrottleDrain' => 0.22,
            ],
            'airframe_spec' => [
                'rotors' => 6,
                'bodyRadius' => 0.15,
                'motorReach' => 0.3,
                'propRadius' => 0.145,
                'bladeLength' => 0.14,
                'legLength' => 0.14,
                'legReachRatio' => 0.62,
                'rotorMaxSpeed' => 82.0,
                'livery' => '#1b1e23',
                'accent' => '#767e87',
            ],
        ];
    }

    /**
     * The airframe every pilot flies unless they have chosen otherwise.
     *
     * Exactly one row may carry this, so a test standing up its own fleet
     * marks one drone with it and no more.
     */
    public function default(): static
    {
        return $this->state(fn (array $attributes): array => [
            'is_default' => true,
            'order' => 0,
        ]);
    }
}
