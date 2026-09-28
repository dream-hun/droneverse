<?php

declare(strict_types=1);

beforeEach(function (): void {
    config(['app.url' => 'https://droneverse.cloud']);
});

test('in production crawlers are kept out of the private areas and pointed at the sitemap', function (): void {
    $this->app->detectEnvironment(fn (): string => 'production');

    $response = $this->get(route('robots', absolute: false));

    $response->assertHeader('Content-Type', 'text/plain; charset=UTF-8');

    expect($response->getContent())->toBe(<<<'TXT'
        User-agent: *
        Disallow: /admin
        Disallow: /api/
        Disallow: /settings

        Sitemap: https://droneverse.cloud/sitemap.xml

        TXT);
});

/**
 * A staging copy that gets crawled competes with the real site for its own
 * name, so anywhere but production turns every crawler away.
 */
test('outside production every crawler is turned away', function (): void {
    $response = $this->get(route('robots', absolute: false));

    expect($response->getContent())->toBe("User-agent: *\nDisallow: /\n");
});

test('robots.txt is served without starting a session', function (): void {
    $response = $this->get(route('robots', absolute: false));

    $response->assertHeaderMissing('Set-Cookie');
});

/**
 * The web server hands out a file in public/ before Laravel sees the
 * request, so a static robots.txt left there would answer instead of the
 * route, in production and everywhere else alike.
 */
test('no static robots.txt shadows the route', function (): void {
    expect(public_path('robots.txt'))->not->toBeFile();
});
