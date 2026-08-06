<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The fleet a pilot chooses an airframe from.
     *
     * Seeded content, not user data: these rows are the catalogue, they are
     * written by DroneSeeder the same way courses and missions are, and
     * nothing in the application creates one at runtime. `slug` is what the
     * seeder matches on, so re-running it revises the fleet in place rather
     * than duplicating it.
     *
     * The two specs are JSON for the same reason `challenges.environment` is:
     * they are read as one blob by one consumer — the simulator, which wants
     * the whole envelope at once — and nothing filters or sorts on an
     * individual constant. Splitting fifteen physics constants and nine
     * geometry numbers into twenty-four columns would buy a query shape no
     * caller has, and would make adding a constant a migration instead of a
     * seeder edit. Their shapes are pinned in App\Models\DroneModel's
     * docblock and mirrored in resources/js/types/drone.ts, which is the same
     * contract `environment` and `success_criteria` already live under.
     *
     * The split between the two is not cosmetic. `flight_spec` is what the
     * control loop reads and is therefore what makes one drone fly
     * differently from another; `airframe_spec` is what the mesh tree is
     * drawn from and what the geometry invariants in
     * resources/js/lib/simulator/airframe.test.ts are checked against. Only
     * `rest_height` spans both — the physics parks the body at it and the
     * landing feet have to reach it — and it lives in `flight_spec`, with the
     * geometry deriving from it, exactly as the constants did before there
     * was more than one drone.
     */
    public function up(): void
    {
        Schema::create('drone_models', function (Blueprint $table): void {
            $table->id();
            $table->uuid()->unique();
            $table->string('slug')->unique();
            $table->string('name');
            $table->string('class');
            $table->string('summary');
            $table->json('flight_spec');
            $table->json('airframe_spec');

            /*
             * The airframe every pilot flies unless they have chosen
             * otherwise — including every pilot whose plan does not reach the
             * picker at all, which is most of them. Exactly one row carries
             * it, and App\Actions\ResolveMissionDrone fails loudly rather
             * than guessing if none does: a fleet with no default is a
             * simulator with no drone, and a mission page that silently
             * picked the lowest id would fly a pilot in something nobody
             * chose.
             */
            $table->boolean('is_default')->default(false);

            $table->unsignedSmallInteger('order')->default(0);
            $table->timestamps();

            $table->index(['order', 'id']);
        });
    }
};
