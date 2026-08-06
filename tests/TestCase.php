<?php

declare(strict_types=1);

namespace Tests;

use Database\Seeders\DroneSeeder;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Laravel\Fortify\Features;

abstract class TestCase extends BaseTestCase
{
    /**
     * The fleet, seeded for every test that touches a database.
     *
     * Not a convenience. The fleet is required content in the same sense the
     * `users` table is required structure: there is no such thing as a
     * simulator with no drone, so every mission page resolves an airframe and
     * {@see \App\Actions\ResolveMissionDrone} refuses to invent one when the
     * catalogue is empty. A suite that ran without it would be testing an
     * application state that cannot be deployed, and would do it by exercising
     * the failure branch on every mission page at once.
     *
     * Seeded here rather than left to each test so a test never has to know
     * that flying a mission needs a fleet — the same way none of them declare
     * that logging in needs a `users` table. Tests that care about a specific
     * airframe still build or fetch one; this only guarantees a default
     * exists to fall back to.
     */
    protected bool $seed = true;

    /** @var class-string<\Illuminate\Database\Seeder> */
    protected string $seeder = DroneSeeder::class;

    protected function skipUnlessFortifyHas(string $feature, ?string $message = null): void
    {
        if (! Features::enabled($feature)) {
            $this->markTestSkipped($message ?? sprintf('Fortify feature [%s] is not enabled.', $feature));
        }
    }
}
