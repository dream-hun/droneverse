<?php

declare(strict_types=1);

namespace App\Actions;

/**
 * Who this deployment says it is, in the form the legal pages read.
 *
 * Both documents open on the same block — the trader a pilot is contracting
 * with, the controller answering for their data, the addresses either can be
 * reached at — because under EU law they are the same disclosure asked for by
 * two different regimes, and a terms page naming one company while the privacy
 * page names another is a defect in both.
 *
 * Everything here is optional in config and nothing here is invented. A value
 * that is not set comes back null and the pages leave a visible gap where it
 * belongs; the alternative — a plausible default address, a placeholder
 * registration number — publishes a false statement about a legal entity to
 * every visitor, which is worse than publishing an incomplete one.
 */
final readonly class BuildLegalIdentity
{
    /**
     * @return array{
     *     name: string,
     *     address: string|null,
     *     country: string|null,
     *     registration: string|null,
     *     supportEmail: string|null,
     *     privacyEmail: string|null,
     *     euRepresentative: array{name: string, address: string|null, email: string|null}|null,
     *     governingLaw: string|null,
     *     hostingRegion: string|null,
     * }
     */
    public function handle(): array
    {
        /*
         * Each address falls back through the other and then through the sales
         * inbox, because the failure being avoided is specific: a page that
         * tells a pilot they have a right and then gives them nowhere to
         * exercise it. Any monitored inbox beats that, and a deployment that
         * has set none of the three has a page telling them to write to us
         * without saying where — which is at least visibly unfinished.
         */
        $support = $this->configured('legal.contact.support');
        $privacy = $this->configured('legal.contact.privacy');
        $sales = $this->configured('plans.sales_email');

        return [
            'name' => $this->configured('legal.entity.name') ?? (string) config('app.name'),
            'address' => $this->configured('legal.entity.address'),
            'country' => $this->configured('legal.entity.country'),
            'registration' => $this->configured('legal.entity.registration'),
            'supportEmail' => $support ?? $privacy ?? $sales,
            'privacyEmail' => $privacy ?? $support ?? $sales,
            'euRepresentative' => $this->euRepresentative(),
            'governingLaw' => $this->configured('legal.governing_law'),
            'hostingRegion' => $this->configured('legal.hosting_region'),
        ];
    }

    /**
     * The Article 27 representative, or nothing at all.
     *
     * Keyed on the name: an address and an inbox with nobody named to them
     * identify no representative, and the section they would render under is a
     * claim that one has been appointed. Half a claim is not published.
     *
     * @return array{name: string, address: string|null, email: string|null}|null
     */
    private function euRepresentative(): ?array
    {
        $name = $this->configured('legal.eu_representative.name');

        if ($name === null) {
            return null;
        }

        return [
            'name' => $name,
            'address' => $this->configured('legal.eu_representative.address'),
            'email' => $this->configured('legal.eu_representative.email'),
        ];
    }

    /**
     * A config value only when it is a string someone actually filled in.
     *
     * An unset environment variable and one set to the empty string mean the
     * same thing to a reader of the page, so they mean the same thing here.
     */
    private function configured(string $key): ?string
    {
        $value = config($key);

        return is_string($value) && mb_trim($value) !== '' ? mb_trim($value) : null;
    }
}
