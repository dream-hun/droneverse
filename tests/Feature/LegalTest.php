<?php

declare(strict_types=1);

test('guests can read the terms', function (): void {
    $response = $this->get(route('terms'));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page->component('legal/terms'));
});

test('guests can read the privacy policy', function (): void {
    $response = $this->get(route('privacy'));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page->component('legal/privacy'));
});

test('both documents state when their wording took effect', function (): void {
    config(['legal.effective.terms' => '2026-08-12']);
    config(['legal.effective.privacy' => '2026-01-31']);

    $this->get(route('terms'))->assertInertia(fn ($page) => $page
        ->where('updatedAt.iso', '2026-08-12')
        ->where('updatedAt.label', '12 August 2026'));

    $this->get(route('privacy'))->assertInertia(fn ($page) => $page
        ->where('updatedAt.iso', '2026-01-31')
        ->where('updatedAt.label', '31 January 2026'));
});

test('the identity block reports the configured trader', function (): void {
    config([
        'legal.entity.name' => 'DroneVerse Ltd',
        'legal.entity.address' => '1 Runway Road, Kigali',
        'legal.entity.country' => 'Rwanda',
        'legal.entity.registration' => 'RDB 123456789',
        'legal.hosting_region' => 'the United States',
    ]);

    $this->get(route('terms'))->assertInertia(fn ($page) => $page
        ->where('identity.name', 'DroneVerse Ltd')
        ->where('identity.address', '1 Runway Road, Kigali')
        ->where('identity.country', 'Rwanda')
        ->where('identity.registration', 'RDB 123456789')
        ->where('identity.hostingRegion', 'the United States'));
});

/**
 * A deployment that has named no trader must not invent one — the pages are
 * built to publish a gap instead, and the application's own name is the
 * only thing standing in for the entity.
 */
test('unconfigured identity details are null rather than guessed', function (): void {
    config([
        'app.name' => 'DroneVerse',
        'legal.entity.name' => null,
        'legal.entity.address' => null,
        'legal.entity.country' => null,
        'legal.entity.registration' => null,
        'legal.hosting_region' => null,
    ]);

    $this->get(route('privacy'))->assertInertia(fn ($page) => $page
        ->where('identity.name', 'DroneVerse')
        ->where('identity.address', null)
        ->where('identity.country', null)
        ->where('identity.registration', null)
        ->where('identity.hostingRegion', null));
});

test('a blank setting counts as unset', function (): void {
    config([
        'app.name' => 'DroneVerse',
        'legal.entity.name' => '   ',
        'legal.entity.address' => '',
    ]);

    $this->get(route('terms'))->assertInertia(fn ($page) => $page
        ->where('identity.name', 'DroneVerse')
        ->where('identity.address', null));
});

/**
 * Neither page may promise a right with nowhere to exercise it, so each
 * address falls back through the other and then through the sales inbox.
 */
test('contact addresses fall back to each other', function (): void {
    config([
        'legal.contact.support' => 'support@droneverse.test',
        'legal.contact.privacy' => null,
        'plans.sales_email' => 'sales@droneverse.test',
    ]);

    $this->get(route('privacy'))->assertInertia(fn ($page) => $page
        ->where('identity.supportEmail', 'support@droneverse.test')
        ->where('identity.privacyEmail', 'support@droneverse.test'));
});

test('contact addresses fall back to the sales inbox last', function (): void {
    config([
        'legal.contact.support' => null,
        'legal.contact.privacy' => null,
        'plans.sales_email' => 'sales@droneverse.test',
    ]);

    $this->get(route('terms'))->assertInertia(fn ($page) => $page
        ->where('identity.supportEmail', 'sales@droneverse.test')
        ->where('identity.privacyEmail', 'sales@droneverse.test'));
});

test('each address is used when both are configured', function (): void {
    config([
        'legal.contact.support' => 'support@droneverse.test',
        'legal.contact.privacy' => 'privacy@droneverse.test',
    ]);

    $this->get(route('privacy'))->assertInertia(fn ($page) => $page
        ->where('identity.supportEmail', 'support@droneverse.test')
        ->where('identity.privacyEmail', 'privacy@droneverse.test'));
});

test('no contact address anywhere leaves the pages saying so', function (): void {
    config([
        'legal.contact.support' => null,
        'legal.contact.privacy' => null,
        'plans.sales_email' => null,
    ]);

    $this->get(route('privacy'))->assertInertia(fn ($page) => $page
        ->where('identity.supportEmail', null)
        ->where('identity.privacyEmail', null));
});

test('the eu representative is published once appointed', function (): void {
    config([
        'legal.eu_representative.name' => 'DroneVerse EU Rep GmbH',
        'legal.eu_representative.address' => 'Hauptstrasse 1, Berlin',
        'legal.eu_representative.email' => 'rep@droneverse.test',
    ]);

    $this->get(route('privacy'))->assertInertia(fn ($page) => $page
        ->where('identity.euRepresentative.name', 'DroneVerse EU Rep GmbH')
        ->where('identity.euRepresentative.address', 'Hauptstrasse 1, Berlin')
        ->where('identity.euRepresentative.email', 'rep@droneverse.test'));
});

/**
 * An address and an inbox with nobody named to them identify no
 * representative, and the section they would render under is a claim that
 * one has been appointed under Article 27. Half a claim is not published.
 */
test('an unnamed eu representative is not published', function (): void {
    config([
        'legal.eu_representative.name' => null,
        'legal.eu_representative.address' => 'Hauptstrasse 1, Berlin',
        'legal.eu_representative.email' => 'rep@droneverse.test',
    ]);

    $this->get(route('privacy'))->assertInertia(fn ($page) => $page
        ->where('identity.euRepresentative', null));
});
