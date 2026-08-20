<?php

declare(strict_types=1);

dataset('icon files', [
    'ico' => ['favicon.ico'],
    'svg' => ['favicon.svg'],
    'apple touch icon' => ['apple-touch-icon.png'],
]);

test('the icon assets are published', function (string $file): void {
    $path = public_path($file);

    $this->assertFileExists($path);
    $this->assertGreaterThan(0, filesize($path));
})->with('icon files');

test('the ico is a valid multi size icon resource', function (): void {
    $handle = fopen(public_path('favicon.ico'), 'rb');
    $header = unpack('vreserved/vtype/vcount', (string) fread($handle, 6));
    fclose($handle);

    $this->assertSame(0, $header['reserved']);
    $this->assertSame(1, $header['type']);
    $this->assertGreaterThanOrEqual(2, $header['count']);
});

test('the svg uses the droneverse mark and brand colour', function (): void {
    $svg = (string) file_get_contents(public_path('favicon.svg'));

    $this->assertStringContainsString('#0084D1', $svg);
    $this->assertStringNotContainsString('#FF2D20', $svg);
});

test('the layout links every icon', function (): void {
    $response = $this->get(route('home'));

    $response->assertOk();
    $response->assertSee('<link rel="icon" href="/favicon.ico" sizes="any">', false);
    $response->assertSee('<link rel="icon" href="/favicon.svg" type="image/svg+xml">', false);
    $response->assertSee('<link rel="apple-touch-icon" href="/apple-touch-icon.png">', false);
});
