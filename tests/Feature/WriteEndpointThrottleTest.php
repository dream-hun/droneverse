<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Challenge;
use App\Models\Course;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * The two endpoints a signed-in pilot can drive in a loop.
 *
 * Both accept work that costs the server real time — grading a flight,
 * decoding and storing an image — so both are capped well above what flying
 * honestly can produce.
 */
final class WriteEndpointThrottleTest extends TestCase
{
    use RefreshDatabase;

    public function test_run_submissions_are_throttled(): void
    {
        $user = User::factory()->create();
        $course = Course::factory()->create();
        $challenge = Challenge::factory()->for($course)->create();

        $url = route('challenges.attempts.store', [$course, $challenge]);
        $payload = [
            'code' => 'async function main(drone) {}',
            'collisions' => 0,
            'photos' => [],
            'path' => [['t' => 0, 'x' => 0, 'y' => 0.15, 'z' => 0]],
        ];

        $this->actingAs($user);

        for ($i = 0; $i < 30; $i++) {
            $this->postJson($url, $payload)->assertOk();
        }

        $this->postJson($url, $payload)->assertStatus(429);
    }

    public function test_the_photo_endpoint_carries_its_own_limit(): void
    {
        $middleware = collect(Route::getRoutes()->getRoutes())
            ->firstWhere(fn ($route): bool => $route->getName() === 'challenges.photos.store')
            ->gatherMiddleware();

        $this->assertContains('throttle:120,1', $middleware);
    }

    public function test_a_pilot_flying_a_normal_mission_is_never_throttled(): void
    {
        $user = User::factory()->create();
        $course = Course::factory()->create();
        $challenge = Challenge::factory()->for($course)->create();

        $url = route('challenges.attempts.store', [$course, $challenge]);

        // A mission runs for at least ten seconds, so half a dozen runs is
        // already a busy minute at the keyboard.
        for ($i = 0; $i < 6; $i++) {
            $this->actingAs($user)->postJson($url, [
                'code' => 'async function main(drone) {}',
                'collisions' => 0,
                'photos' => [],
                'path' => [['t' => 0, 'x' => 0, 'y' => 0.15, 'z' => 0]],
            ])->assertOk();
        }
    }
}
