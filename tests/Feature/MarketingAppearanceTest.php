<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The public pages render in .theme-droneverse, which has no light variant.
 *
 * So the shell around them has to be dark whatever the visitor prefers. This
 * is asserted on the HTML the server sends rather than on anything React does,
 * because the failure it guards against happens before React runs: a
 * light-preference visitor seeing a white page for as long as the bundle takes
 * to arrive, and white gutters around a black page every time they overscroll.
 */
final class MarketingAppearanceTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, array{string}>
     */
    public static function publicPages(): array
    {
        return [
            'landing' => ['home'],
            'pricing' => ['pricing'],
            'terms' => ['terms'],
            'privacy' => ['privacy'],
        ];
    }

    #[DataProvider('publicPages')]
    public function test_public_pages_render_dark_even_for_a_light_preference(string $route): void
    {
        $response = $this->withUnencryptedCookie('appearance', 'light')->get(route($route));

        $response->assertOk();
        $response->assertSee('class="dark"', false);
        $response->assertSee('color-scheme: dark !important', false);
    }

    /**
     * The counterpart: the app itself does follow the preference, and the
     * override must not leak onto it.
     */
    public function test_the_app_still_follows_a_light_preference(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)
            ->withUnencryptedCookie('appearance', 'light')
            ->get(route('dashboard'));

        $response->assertOk();
        $response->assertDontSee('color-scheme: dark !important', false);
    }

    public function test_the_app_still_follows_a_dark_preference(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)
            ->withUnencryptedCookie('appearance', 'dark')
            ->get(route('dashboard'));

        $response->assertOk();
        $response->assertSee('class="dark"', false);
        $response->assertDontSee('color-scheme: dark !important', false);
    }
}
