<?php

declare(strict_types=1);

use App\Models\Challenge;
use App\Models\Course;
use App\Models\User;
use Illuminate\Support\Facades\Route;

/*
 * The two endpoints a signed-in pilot can drive in a loop.
 *
 * Both accept work that costs the server real time — grading a flight,
 * decoding and storing an image — so both are capped well above what flying
 * honestly can produce.
 */

test('run submissions are throttled', function (): void {
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
});

test('the photo endpoint carries its own limit', function (): void {
    $middleware = collect(Route::getRoutes()->getRoutes())
        ->firstWhere(fn ($route): bool => $route->getName() === 'challenges.photos.store')
        ->gatherMiddleware();

    expect('throttle:120,1')->toBeIn($middleware);
});

test('a pilot flying a normal mission is never throttled', function (): void {
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
});
