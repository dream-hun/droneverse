/**
 * The trader and controller block both legal pages open on.
 *
 * Every field but the name is nullable because every field but the name comes
 * from an environment variable that a deployment may not have set, and the
 * pages are built to show a gap rather than to guess. Built by
 * App\Actions\BuildLegalIdentity.
 */
export type LegalIdentity = {
    name: string;
    address: string | null;
    country: string | null;
    registration: string | null;
    supportEmail: string | null;
    privacyEmail: string | null;
    /** GDPR Article 27. Null until one is actually appointed and configured. */
    euRepresentative: {
        name: string;
        address: string | null;
        email: string | null;
    } | null;
    /** Where the data sits, in plain words: "the United States". */
    hostingRegion: string | null;
};

/**
 * When the wording on the page took effect.
 *
 * Formatted on the server, so the date a pilot reads is the date every other
 * pilot reads regardless of their locale, and `iso` is what the `<time>`
 * element carries for anything parsing the page.
 */
export type LegalRevision = {
    iso: string;
    label: string;
};
