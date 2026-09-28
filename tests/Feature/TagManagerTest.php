<?php

declare(strict_types=1);

use Inertia\Testing\AssertableInertia;

/*
 * Google Tag Manager loads only for a visitor who accepted analytics on the
 * consent banner, and only in production. The privacy policy promises the
 * first and the reports depend on the second, so both are asserted on the
 * HTML the server sends: the snippet is printed into the <head> there, before
 * any of our JavaScript runs.
 *
 * The consent cookie is sent unencrypted because the browser writes it, not
 * Laravel. That also holds the cookie to being left out of encryption: were
 * it encrypted, the plain value would fail to decrypt and read as no choice.
 */

beforeEach(function (): void {
    config(['services.google_tag_manager.container_id' => 'GTM-TEST123']);
});

test('the container loads in production for a visitor who accepted analytics', function (): void {
    $this->app->detectEnvironment(fn (): string => 'production');

    $response = $this->withUnencryptedCookie('analytics_consent', 'granted')->get(route('home'));

    $response->assertSee("'https://www.googletagmanager.com/gtm.js?id='", false);
    $response->assertSee("'script','dataLayer','GTM-TEST123'", false);
    $response->assertSee('<noscript><iframe src="https://www.googletagmanager.com/ns.html?id=GTM-TEST123"', false);
});

dataset('no consent', [
    'a rejection' => ['denied'],
    'a value the banner never writes' => ['yes please'],
]);

test('nothing from Google loads for a visitor who has not accepted', function (string $consent): void {
    $this->app->detectEnvironment(fn (): string => 'production');

    $response = $this->withUnencryptedCookie('analytics_consent', $consent)->get(route('home'));

    $response->assertDontSee('googletagmanager.com', false);
})->with('no consent');

test('nothing from Google loads before a visitor has chosen', function (): void {
    $this->app->detectEnvironment(fn (): string => 'production');

    $response = $this->get(route('home'));

    $response->assertDontSee('googletagmanager.com', false);
    $response->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
        ->where('tagManager.containerId', 'GTM-TEST123')
        ->where('tagManager.consent', null));
});

/**
 * Local and staging traffic would otherwise land in the real reports.
 */
test('the container never loads outside production', function (): void {
    $response = $this->withUnencryptedCookie('analytics_consent', 'granted')->get(route('home'));

    $response->assertDontSee('googletagmanager.com', false);
    $response->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
        ->where('tagManager.containerId', null)
        ->where('tagManager.consent', 'granted'));
});

test('an empty container id turns the tag manager off', function (): void {
    $this->app->detectEnvironment(fn (): string => 'production');
    config(['services.google_tag_manager.container_id' => '']);

    $response = $this->withUnencryptedCookie('analytics_consent', 'granted')->get(route('home'));

    $response->assertDontSee('googletagmanager.com', false);
    $response->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
        ->where('tagManager.containerId', null));
});
