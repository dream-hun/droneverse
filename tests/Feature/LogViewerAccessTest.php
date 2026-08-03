<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

/**
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
final class LogViewerAccessTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Defining the gate is load-bearing in a way nothing else would catch:
     * its absence does not raise, it just returns the middleware to guarding
     * only exact-production environments.
     */
    public function test_the_view_log_viewer_gate_is_defined(): void
    {
        $this->assertTrue(Gate::has('viewLogViewer'));
    }

    public function test_guests_cannot_reach_the_log_viewer(): void
    {
        $this->get(route('log-viewer.index'))->assertForbidden();
    }

    public function test_an_ordinary_pilot_cannot_reach_the_log_viewer(): void
    {
        $this->actingAs(User::factory()->create())
            ->get(route('log-viewer.index'))
            ->assertForbidden();
    }

    public function test_an_allowlisted_operator_can_reach_the_log_viewer(): void
    {
        $user = User::factory()->create(['email' => 'ops@droneverse.test']);

        config()->set('logging.viewer_emails', ['ops@droneverse.test']);

        $this->actingAs($user)
            ->get(route('log-viewer.index'))
            ->assertOk();
    }

    /**
     * The default state, and the one that matters most: an environment that
     * enables the viewer without naming anybody grants nothing.
     */
    public function test_an_empty_allowlist_denies_everyone(): void
    {
        config()->set('logging.viewer_emails', []);

        $this->actingAs(User::factory()->create())
            ->get(route('log-viewer.index'))
            ->assertForbidden();
    }

    /**
     * The allowlist names one address, not a shape of address. A pilot who
     * signs up as `ops@droneverse.test.attacker.example` must not match.
     */
    public function test_the_allowlist_matches_addresses_exactly(): void
    {
        config()->set('logging.viewer_emails', ['ops@droneverse.test']);

        $impostor = User::factory()->create([
            'email' => 'ops@droneverse.test.attacker.example',
        ]);

        $this->actingAs($impostor)
            ->get(route('log-viewer.index'))
            ->assertForbidden();
    }

    /**
     * The log contents API is a separate route group with its own middleware
     * stack, so it is gated separately from the page that reads it.
     */
    public function test_the_log_contents_api_is_gated_too(): void
    {
        $this->actingAs(User::factory()->create())
            ->getJson(route('log-viewer.logs'))
            ->assertForbidden();
    }

    /**
     * Deleting a log file is the destructive half of the surface, and it is
     * reachable by an unauthenticated DELETE without a gate in place.
     */
    public function test_guests_cannot_delete_log_files(): void
    {
        $this->deleteJson(route('log-viewer.files.delete', ['fileIdentifier' => 'laravel.log']))
            ->assertForbidden();
    }
}
