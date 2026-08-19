<?php

declare(strict_types=1);

namespace Tests;

use Illuminate\Database\Seeder;
use Database\Seeders\DroneSeeder;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Inertia\Inertia;
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

    /** @var class-string<Seeder> */
    protected string $seeder = DroneSeeder::class;

    /**
     * Render every Inertia response on this side of the wire.
     *
     * SSR is on in config, and Inertia dispatches it over HTTP: to the Vite
     * dev server when `public/hot` exists, to the SSR bundle otherwise. Left
     * alone, a developer running `npm run dev` turns every `assertInertia`
     * test in the suite into a live round trip to that dev server — and the
     * two classes that call `Http::preventStrayRequests()` into 33 failures,
     * because {@see \Inertia\Ssr\HttpGateway::dispatch()} deliberately
     * rethrows a StrayRequestException rather than falling back.
     *
     * The suite passed in CI throughout, which is the part worth guarding
     * against: CI builds without an SSR bundle, so the gateway skips SSR and
     * the whole question never arises there. A test that depends on whether a
     * dev server happens to be running is a test that reports on the machine
     * rather than on the code.
     *
     * Nothing is lost by turning it off. `assertInertia` reads the page
     * object out of the response either way, and no assertion in the suite is
     * about server-rendered markup.
     */
    protected function setUp(): void
    {
        parent::setUp();

        Inertia::disableSsr();
    }

    protected function skipUnlessFortifyHas(string $feature, ?string $message = null): void
    {
        if (! Features::enabled($feature)) {
            $this->markTestSkipped($message ?? sprintf('Fortify feature [%s] is not enabled.', $feature));
        }
    }
}
