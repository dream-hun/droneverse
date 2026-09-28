import type { ConsentChoice } from '@/types/consent';

/**
 * The cookie holding what a visitor chose on the consent banner.
 *
 * A cookie rather than local storage because the server needs it too:
 * the root template prints Google Tag Manager's snippet at the top of the
 * `<head>` for a visitor who accepted, before any script of ours has run.
 * Remembering the choice is strictly necessary to honour it, so this cookie
 * is set without asking, and it is listed in the privacy policy as such.
 */
export const CONSENT_COOKIE = 'analytics_consent';

/**
 * Six months, after which the question is asked again: the period the French
 * regulator, the CNIL, recommends for both an acceptance and a refusal.
 */
const CONSENT_MAX_AGE = 60 * 60 * 24 * 182;

/** The cookies Google's analytics and advertising tags set on this site's domain. */
const GOOGLE_COOKIE = /^(_ga|_gid|_gat|_gcl)/;

export function rememberConsent(consent: ConsentChoice): void {
    const secure = window.location.protocol === 'https:' ? ';Secure' : '';

    document.cookie = `${CONSENT_COOKIE}=${consent};path=/;max-age=${CONSENT_MAX_AGE};SameSite=Lax${secure}`;
}

/**
 * Load the container the way Google's own snippet does, for a visitor who
 * accepted on this page. On every page after, the root template prints the
 * snippet itself, so this checks it is not already there before adding it.
 */
export function loadTagManager(containerId: string): void {
    if (
        document.querySelector(
            'script[src^="https://www.googletagmanager.com/gtm.js"]',
        )
    ) {
        return;
    }

    const w = window as typeof window & { dataLayer?: unknown[] };
    w.dataLayer = w.dataLayer ?? [];
    w.dataLayer.push({ 'gtm.start': new Date().getTime(), event: 'gtm.js' });

    const script = document.createElement('script');
    script.async = true;
    script.src = `https://www.googletagmanager.com/gtm.js?id=${encodeURIComponent(containerId)}`;
    document.head.appendChild(script);
}

/**
 * Delete what Google's tags stored, when a visitor withdraws consent.
 *
 * Google sets these on the site's registrable domain rather than on the exact
 * host, and a cookie is only removed by naming the domain it was set on, so
 * each is expired on the host and on every parent domain above it.
 */
export function forgetGoogleCookies(): void {
    const names = document.cookie
        .split(';')
        .map((cookie) => cookie.split('=')[0].trim())
        .filter((name) => GOOGLE_COOKIE.test(name));

    const labels = window.location.hostname.split('.');
    const domains = labels
        .slice(0, -1)
        .map((_, index) => labels.slice(index).join('.'));

    for (const name of names) {
        document.cookie = `${name}=;path=/;max-age=0`;

        for (const domain of domains) {
            document.cookie = `${name}=;path=/;max-age=0;domain=${domain}`;
        }
    }
}

/**
 * Start the page again, so a container that has already run is gone.
 *
 * Its own function because jsdom will not let a test replace
 * `window.location.reload`, and a withdrawal that reloads is the one path the
 * banner's tests most need to see taken.
 */
export function reloadPage(): void {
    window.location.reload();
}

/*
 * The "Cookie settings" controls in the footer and the privacy policy reopen
 * the banner, which is mounted once for the whole app. A tiny store rather
 * than context, because the banner renders outside the Inertia page tree.
 */
let settingsOpen = false;
const listeners = new Set<() => void>();

function notify(): void {
    for (const listener of listeners) {
        listener();
    }
}

export function subscribeToCookieSettings(onChange: () => void): () => void {
    listeners.add(onChange);

    return () => {
        listeners.delete(onChange);
    };
}

export function cookieSettingsOpen(): boolean {
    return settingsOpen;
}

export function openCookieSettings(): void {
    settingsOpen = true;
    notify();
}

export function closeCookieSettings(): void {
    settingsOpen = false;
    notify();
}
