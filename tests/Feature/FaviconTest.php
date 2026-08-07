<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class FaviconTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, array{0: string}>
     */
    public static function iconFiles(): array
    {
        return [
            'ico' => ['favicon.ico'],
            'svg' => ['favicon.svg'],
            'apple touch icon' => ['apple-touch-icon.png'],
        ];
    }

    #[DataProvider('iconFiles')]
    public function test_the_icon_assets_are_published(string $file): void
    {
        $path = public_path($file);

        $this->assertFileExists($path);
        $this->assertGreaterThan(0, filesize($path));
    }

    public function test_the_ico_is_a_valid_multi_size_icon_resource(): void
    {
        $handle = fopen(public_path('favicon.ico'), 'rb');
        $header = unpack('vreserved/vtype/vcount', (string) fread($handle, 6));
        fclose($handle);

        $this->assertSame(0, $header['reserved']);
        $this->assertSame(1, $header['type']);
        $this->assertGreaterThanOrEqual(2, $header['count']);
    }

    public function test_the_svg_uses_the_droneverse_mark_and_brand_colour(): void
    {
        $svg = (string) file_get_contents(public_path('favicon.svg'));

        $this->assertStringContainsString('#0084D1', $svg);
        $this->assertStringNotContainsString('#FF2D20', $svg);
    }

    public function test_the_layout_links_every_icon(): void
    {
        $response = $this->get(route('home'));

        $response->assertOk();
        $response->assertSee('<link rel="icon" href="/favicon.ico" sizes="any">', false);
        $response->assertSee('<link rel="icon" href="/favicon.svg" type="image/svg+xml">', false);
        $response->assertSee('<link rel="apple-touch-icon" href="/apple-touch-icon.png">', false);
    }
}
