<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class LegalTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_can_read_the_terms(): void
    {
        $response = $this->get(route('terms'));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page->component('legal/terms'));
    }

    public function test_guests_can_read_the_privacy_policy(): void
    {
        $response = $this->get(route('privacy'));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page->component('legal/privacy'));
    }

    public function test_both_documents_state_when_their_wording_took_effect(): void
    {
        config(['legal.effective.terms' => '2026-08-12']);
        config(['legal.effective.privacy' => '2026-01-31']);

        $this->get(route('terms'))->assertInertia(fn ($page) => $page
            ->where('updatedAt.iso', '2026-08-12')
            ->where('updatedAt.label', '12 August 2026'));

        $this->get(route('privacy'))->assertInertia(fn ($page) => $page
            ->where('updatedAt.iso', '2026-01-31')
            ->where('updatedAt.label', '31 January 2026'));
    }

    public function test_the_identity_block_reports_the_configured_trader(): void
    {
        config([
            'legal.entity.name' => 'DroneVerse Ltd',
            'legal.entity.address' => '1 Runway Road, Kigali',
            'legal.entity.country' => 'Rwanda',
            'legal.entity.registration' => 'RDB 123456789',
            'legal.governing_law' => 'the laws of Rwanda',
            'legal.hosting_region' => 'the United States',
        ]);

        $this->get(route('terms'))->assertInertia(fn ($page) => $page
            ->where('identity.name', 'DroneVerse Ltd')
            ->where('identity.address', '1 Runway Road, Kigali')
            ->where('identity.country', 'Rwanda')
            ->where('identity.registration', 'RDB 123456789')
            ->where('identity.governingLaw', 'the laws of Rwanda')
            ->where('identity.hostingRegion', 'the United States'));
    }

    /**
     * A deployment that has named no trader must not invent one — the pages are
     * built to publish a gap instead, and the application's own name is the
     * only thing standing in for the entity.
     */
    public function test_unconfigured_identity_details_are_null_rather_than_guessed(): void
    {
        config([
            'app.name' => 'DroneVerse',
            'legal.entity.name' => null,
            'legal.entity.address' => null,
            'legal.entity.country' => null,
            'legal.entity.registration' => null,
            'legal.governing_law' => null,
            'legal.hosting_region' => null,
        ]);

        $this->get(route('privacy'))->assertInertia(fn ($page) => $page
            ->where('identity.name', 'DroneVerse')
            ->where('identity.address', null)
            ->where('identity.country', null)
            ->where('identity.registration', null)
            ->where('identity.governingLaw', null)
            ->where('identity.hostingRegion', null));
    }

    public function test_a_blank_setting_counts_as_unset(): void
    {
        config([
            'app.name' => 'DroneVerse',
            'legal.entity.name' => '   ',
            'legal.entity.address' => '',
        ]);

        $this->get(route('terms'))->assertInertia(fn ($page) => $page
            ->where('identity.name', 'DroneVerse')
            ->where('identity.address', null));
    }

    /**
     * Neither page may promise a right with nowhere to exercise it, so each
     * address falls back through the other and then through the sales inbox.
     */
    public function test_contact_addresses_fall_back_to_each_other(): void
    {
        config([
            'legal.contact.support' => 'support@droneverse.test',
            'legal.contact.privacy' => null,
            'plans.sales_email' => 'sales@droneverse.test',
        ]);

        $this->get(route('privacy'))->assertInertia(fn ($page) => $page
            ->where('identity.supportEmail', 'support@droneverse.test')
            ->where('identity.privacyEmail', 'support@droneverse.test'));
    }

    public function test_contact_addresses_fall_back_to_the_sales_inbox_last(): void
    {
        config([
            'legal.contact.support' => null,
            'legal.contact.privacy' => null,
            'plans.sales_email' => 'sales@droneverse.test',
        ]);

        $this->get(route('terms'))->assertInertia(fn ($page) => $page
            ->where('identity.supportEmail', 'sales@droneverse.test')
            ->where('identity.privacyEmail', 'sales@droneverse.test'));
    }

    public function test_each_address_is_used_when_both_are_configured(): void
    {
        config([
            'legal.contact.support' => 'support@droneverse.test',
            'legal.contact.privacy' => 'privacy@droneverse.test',
        ]);

        $this->get(route('privacy'))->assertInertia(fn ($page) => $page
            ->where('identity.supportEmail', 'support@droneverse.test')
            ->where('identity.privacyEmail', 'privacy@droneverse.test'));
    }

    public function test_no_contact_address_anywhere_leaves_the_pages_saying_so(): void
    {
        config([
            'legal.contact.support' => null,
            'legal.contact.privacy' => null,
            'plans.sales_email' => null,
        ]);

        $this->get(route('privacy'))->assertInertia(fn ($page) => $page
            ->where('identity.supportEmail', null)
            ->where('identity.privacyEmail', null));
    }

    public function test_the_eu_representative_is_published_once_appointed(): void
    {
        config([
            'legal.eu_representative.name' => 'DroneVerse EU Rep GmbH',
            'legal.eu_representative.address' => 'Hauptstrasse 1, Berlin',
            'legal.eu_representative.email' => 'rep@droneverse.test',
        ]);

        $this->get(route('privacy'))->assertInertia(fn ($page) => $page
            ->where('identity.euRepresentative.name', 'DroneVerse EU Rep GmbH')
            ->where('identity.euRepresentative.address', 'Hauptstrasse 1, Berlin')
            ->where('identity.euRepresentative.email', 'rep@droneverse.test'));
    }

    /**
     * An address and an inbox with nobody named to them identify no
     * representative, and the section they would render under is a claim that
     * one has been appointed under Article 27. Half a claim is not published.
     */
    public function test_an_unnamed_eu_representative_is_not_published(): void
    {
        config([
            'legal.eu_representative.name' => null,
            'legal.eu_representative.address' => 'Hauptstrasse 1, Berlin',
            'legal.eu_representative.email' => 'rep@droneverse.test',
        ]);

        $this->get(route('privacy'))->assertInertia(fn ($page) => $page
            ->where('identity.euRepresentative', null));
    }
}
