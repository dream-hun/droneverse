<?php

declare(strict_types=1);

beforeEach(function (): void {
    config(['app.url' => 'https://droneverse.cloud']);
});

test('the www host redirects permanently to the canonical host, keeping the path and query', function (): void {
    $response = $this->get('http://www.droneverse.cloud/pricing?ref=newsletter');

    $response->assertMovedPermanently();
    $response->assertRedirect('https://droneverse.cloud/pricing?ref=newsletter');
});

test('the canonical host is served directly', function (): void {
    $this->get('https://droneverse.cloud/pricing')->assertOk();
});

/**
 * Only the www. twin is folded in. Redirecting every host that is not APP_URL's
 * would take the whole site down the day APP_URL is set wrong.
 */
test('other hosts are served rather than redirected', function (): void {
    $this->get('https://staging.droneverse.cloud/pricing')->assertOk();
});

/**
 * A browser replays a redirected POST as a GET, which would drop the form.
 */
test('a form posted to the www host is handled rather than redirected', function (): void {
    $response = $this->post('https://www.droneverse.cloud/login');

    $response->assertSessionHasErrors(['email', 'password']);
});
