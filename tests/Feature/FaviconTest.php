<?php

declare(strict_types=1);

dataset('icon files', [
    'ico' => ['favicon.ico'],
    'svg' => ['favicon.svg'],
    'apple touch icon' => ['apple-touch-icon.png'],
]);

test('the icon assets are published', function (string $file): void {
    $path = public_path($file);

    expect($path)->toBeFile();
    expect(filesize($path))->toBeGreaterThan(0);
})->with('icon files');

test('the ico is a valid multi size icon resource', function (): void {
    $handle = fopen(public_path('favicon.ico'), 'rb');
    $header = unpack('vreserved/vtype/vcount', (string) fread($handle, 6));
    fclose($handle);

    expect($header['reserved'])->toBe(0);
    expect($header['type'])->toBe(1);
    expect($header['count'])->toBeGreaterThanOrEqual(2);
});

test('the svg uses the droneverse mark and brand colour', function (): void {
    $svg = (string) file_get_contents(public_path('favicon.svg'));

    expect($svg)->toContain('#0084D1');
    expect($svg)->not->toContain('#FF2D20');
});

test('the layout links every icon', function (): void {
    $response = $this->get(route('home'));

    $response->assertOk();
    $response->assertSee('<link rel="icon" href="/favicon.ico" sizes="any">', false);
    $response->assertSee('<link rel="icon" href="/favicon.svg" type="image/svg+xml">', false);
    $response->assertSee('<link rel="apple-touch-icon" href="/apple-touch-icon.png">', false);
});
