<?php

declare(strict_types=1);

use App\Models\User;

/*
 * The public pages render in .theme-droneverse, which has no light variant.
 *
 * So the shell around them has to be dark whatever the visitor prefers. This
 * is asserted on the HTML the server sends rather than on anything React does,
 * because the failure it guards against happens before React runs: a
 * light-preference visitor seeing a white page for as long as the bundle takes
 * to arrive, and white gutters around a black page every time they overscroll.
 */

dataset('public pages', [
    'landing' => ['home'],
    'pricing' => ['pricing'],
    'terms' => ['terms'],
    'privacy' => ['privacy'],
]);

test('public pages render dark even for a light preference', function (string $route): void {
    $response = $this->withUnencryptedCookie('appearance', 'light')->get(route($route));

    $response->assertOk();
    $response->assertSee('class="dark"', false);
    $response->assertSee('color-scheme: dark !important', false);
})->with('public pages');

/**
 * The counterpart: the app itself does follow the preference, and the
 * override must not leak onto it.
 */
test('the app still follows a light preference', function (): void {
    $user = User::factory()->create();

    $response = $this->actingAs($user)
        ->withUnencryptedCookie('appearance', 'light')
        ->get(route('dashboard'));

    $response->assertOk();
    $response->assertDontSee('color-scheme: dark !important', false);
});

test('the app still follows a dark preference', function (): void {
    $user = User::factory()->create();

    $response = $this->actingAs($user)
        ->withUnencryptedCookie('appearance', 'dark')
        ->get(route('dashboard'));

    $response->assertOk();
    $response->assertSee('class="dark"', false);
    $response->assertDontSee('color-scheme: dark !important', false);
});
