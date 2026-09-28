/** What a visitor chose on the consent banner. */
export type ConsentChoice = 'granted' | 'denied';

/**
 * Google Tag Manager as the server sees it for this visitor. Shared on every
 * page by App\Http\Middleware\HandleInertiaRequests.
 */
export type TagManager = {
    /** The container to load once consent is given; null outside production. */
    containerId: string | null;
    /** The choice in the visitor's cookie when the page was served, or null if they have not made one. */
    consent: ConsentChoice | null;
};
