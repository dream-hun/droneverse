// @vitest-environment jsdom

import {
    act,
    cleanup,
    fireEvent,
    render,
    screen,
} from '@testing-library/react';
import type { ReactNode } from 'react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import {
    CookieConsent,
    CookieSettingsButton,
} from '@/components/cookie-consent';
import { closeCookieSettings, reloadPage } from '@/lib/analytics-consent';
import type * as AnalyticsConsent from '@/lib/analytics-consent';

vi.mock('@inertiajs/react', () => ({
    Link: ({ href, children }: { href: string; children: ReactNode }) => (
        <a href={href}>{children}</a>
    ),
}));

/*
 * Only the reload is replaced: jsdom will not let a test stub
 * `window.location.reload`, and everything else in the module is what these
 * tests are here to exercise.
 */
vi.mock('@/lib/analytics-consent', async (importOriginal) => ({
    ...(await importOriginal<typeof AnalyticsConsent>()),
    reloadPage: vi.fn(),
}));

const CONTAINER = 'GTM-TEST123';

function expireEveryCookie(): void {
    for (const cookie of document.cookie.split(';')) {
        const name = cookie.split('=')[0].trim();

        if (name) {
            document.cookie = `${name}=;path=/;max-age=0`;
        }
    }
}

function tagManagerScripts(): HTMLScriptElement[] {
    return [
        ...document.querySelectorAll<HTMLScriptElement>(
            'script[src^="https://www.googletagmanager.com/gtm.js"]',
        ),
    ];
}

afterEach(() => {
    cleanup();
    act(() => closeCookieSettings());
    expireEveryCookie();
    tagManagerScripts().forEach((script) => script.remove());
    delete (window as typeof window & { dataLayer?: unknown[] }).dataLayer;
    vi.mocked(reloadPage).mockClear();
});

function banner(): HTMLElement | null {
    return screen.queryByRole('region', { name: 'Cookie consent' });
}

describe('CookieConsent', () => {
    it('asks a visitor who has not chosen yet', () => {
        render(<CookieConsent containerId={CONTAINER} consent={null} />);

        expect(banner()).not.toBeNull();
    });

    it('stays away once a choice has been made', () => {
        render(<CookieConsent containerId={CONTAINER} consent="denied" />);

        expect(banner()).toBeNull();
    });

    /**
     * Regulators treat a refusal that takes more effort than consent, or is
     * made to look less like an option, as no refusal at all.
     */
    it('offers reject and accept with the same weight', () => {
        render(<CookieConsent containerId={CONTAINER} consent={null} />);

        const reject = screen.getByRole('button', { name: 'Reject' });
        const accept = screen.getByRole('button', { name: 'Accept' });

        expect(reject.className).toBe(accept.className);
    });

    it('loads the container and remembers the choice once accepted', () => {
        render(<CookieConsent containerId={CONTAINER} consent={null} />);

        fireEvent.click(screen.getByRole('button', { name: 'Accept' }));

        expect(document.cookie).toContain('analytics_consent=granted');
        expect(tagManagerScripts().map((script) => script.src)).toEqual([
            `https://www.googletagmanager.com/gtm.js?id=${CONTAINER}`,
        ]);
        expect(
            (window as typeof window & { dataLayer?: unknown[] }).dataLayer,
        ).toEqual([{ 'gtm.start': expect.any(Number), event: 'gtm.js' }]);
        expect(banner()).toBeNull();
    });

    it('loads nothing and remembers the refusal once rejected', () => {
        render(<CookieConsent containerId={CONTAINER} consent={null} />);

        fireEvent.click(screen.getByRole('button', { name: 'Reject' }));

        expect(document.cookie).toContain('analytics_consent=denied');
        expect(tagManagerScripts()).toEqual([]);
        expect(banner()).toBeNull();
    });

    /**
     * Outside production the server withholds the container, so a yes is
     * recorded but there is nothing to load.
     */
    it('loads nothing where no container is configured', () => {
        render(<CookieConsent containerId={null} consent={null} />);

        fireEvent.click(screen.getByRole('button', { name: 'Accept' }));

        expect(document.cookie).toContain('analytics_consent=granted');
        expect(tagManagerScripts()).toEqual([]);
    });

    /**
     * The root template prints the container for a visitor who accepted on an
     * earlier page, so accepting again from Cookie settings must not add a
     * second copy beside it.
     */
    it('does not load the container a second time', () => {
        const printed = document.createElement('script');
        printed.src = `https://www.googletagmanager.com/gtm.js?id=${CONTAINER}`;
        document.head.appendChild(printed);
        render(<CookieConsent containerId={CONTAINER} consent={null} />);

        fireEvent.click(screen.getByRole('button', { name: 'Accept' }));

        expect(tagManagerScripts()).toHaveLength(1);
    });

    it('comes back from cookie settings to show the current choice', () => {
        render(
            <>
                <CookieConsent containerId={CONTAINER} consent="granted" />
                <CookieSettingsButton />
            </>,
        );

        fireEvent.click(
            screen.getByRole('button', { name: 'Cookie settings' }),
        );

        expect(banner()).not.toBeNull();
        expect(
            screen.getByText('Right now you have accepted analytics.'),
        ).toBeDefined();
    });

    /**
     * A container that has run cannot be unloaded, so withdrawing deletes
     * what Google stored and starts the page again without it. Cookies that
     * are not Google's are left alone.
     */
    it('deletes Google cookies and reloads when consent is withdrawn', () => {
        document.cookie = '_ga=GA1.1.123;path=/';
        document.cookie = '_ga_ABC123=GS1.1.456;path=/';
        document.cookie = 'appearance=dark;path=/';
        render(
            <>
                <CookieConsent containerId={CONTAINER} consent="granted" />
                <CookieSettingsButton />
            </>,
        );

        fireEvent.click(
            screen.getByRole('button', { name: 'Cookie settings' }),
        );
        fireEvent.click(screen.getByRole('button', { name: 'Reject' }));

        expect(document.cookie).toContain('analytics_consent=denied');
        expect(document.cookie).not.toContain('_ga');
        expect(document.cookie).toContain('appearance=dark');
        expect(reloadPage).toHaveBeenCalledOnce();
    });

    it('points at the cookies section of the privacy policy', () => {
        render(<CookieConsent containerId={CONTAINER} consent={null} />);

        const link = screen.getByRole('link', { name: 'privacy policy' });

        expect(link.getAttribute('href')).toBe('/privacy#cookies');
    });
});
