<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\Gate;

/*
 * Who may reach opcodesio/log-viewer.
 *
 * The package guards its own routes only when four conditions hold at once,
 * one of which is `App::isProduction()` — a literal `APP_ENV === 'production'`
 * check, which no staging, preview or uat environment satisfies. The
 * `viewLogViewer` gate in AppServiceProvider is what replaces that, and the
 * package's AuthorizeLogViewer middleware defers to it in every environment
 * once it exists. These tests run under `APP_ENV=testing`, which is precisely
 * the case the package's own check does not cover, so a regression that
 * reinstated environment-dependent authorization would fail here.
 */

/**
 * Defining the gate is load-bearing in a way nothing else would catch:
 * its absence does not raise, it just returns the middleware to guarding
 * only exact-production environments.
 */
test('the view log viewer gate is defined', function (): void {
    $this->assertTrue(Gate::has('viewLogViewer'));
});

test('guests cannot reach the log viewer', function (): void {
    $this->get(route('log-viewer.index'))->assertForbidden();
});

test('an ordinary pilot cannot reach the log viewer', function (): void {
    $this->actingAs(User::factory()->create())
        ->get(route('log-viewer.index'))
        ->assertForbidden();
});

test('an allowlisted operator can reach the log viewer', function (): void {
    $user = User::factory()->create(['email' => 'ops@droneverse.test']);

    config()->set('logging.viewer_emails', ['ops@droneverse.test']);

    $this->actingAs($user)
        ->get(route('log-viewer.index'))
        ->assertOk();
});

/**
 * The default state, and the one that matters most: an environment that
 * enables the viewer without naming anybody grants nothing.
 */
test('an empty allowlist denies everyone', function (): void {
    config()->set('logging.viewer_emails', []);

    $this->actingAs(User::factory()->create())
        ->get(route('log-viewer.index'))
        ->assertForbidden();
});

/**
 * The allowlist names one address, not a shape of address. A pilot who
 * signs up as `ops@droneverse.test.attacker.example` must not match.
 */
test('the allowlist matches addresses exactly', function (): void {
    config()->set('logging.viewer_emails', ['ops@droneverse.test']);

    $impostor = User::factory()->create([
        'email' => 'ops@droneverse.test.attacker.example',
    ]);

    $this->actingAs($impostor)
        ->get(route('log-viewer.index'))
        ->assertForbidden();
});

/**
 * The log contents API is a separate route group with its own middleware
 * stack, so it is gated separately from the page that reads it.
 */
test('the log contents api is gated too', function (): void {
    $this->actingAs(User::factory()->create())
        ->getJson(route('log-viewer.logs'))
        ->assertForbidden();
});

/**
 * Deleting a log file is the destructive half of the surface, and it is
 * reachable by an unauthenticated DELETE without a gate in place.
 */
test('guests cannot delete log files', function (): void {
    $this->deleteJson(route('log-viewer.files.delete', ['fileIdentifier' => 'laravel.log']))
        ->assertForbidden();
});
