// @vitest-environment jsdom

import {
    act,
    cleanup,
    fireEvent,
    render,
    screen,
} from '@testing-library/react';
import type { ReactNode } from 'react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { CookieNotice } from '@/components/cookie-notice';

vi.mock('@inertiajs/react', () => ({
    Link: ({ href, children }: { href: string; children: ReactNode }) => (
        <a href={href}>{children}</a>
    ),
}));

const STORAGE_KEY = 'cookie-notice-acknowledged';

beforeEach(() => {
    localStorage.clear();
});

afterEach(() => {
    cleanup();
    vi.unstubAllGlobals();
});

describe('CookieNotice', () => {
    it('is shown to a visitor who has not seen it', async () => {
        render(<CookieNotice />);

        expect(
            await screen.findByRole('region', { name: 'Cookie notice' }),
        ).toBeDefined();
    });

    it('stays away once it has been acknowledged', () => {
        localStorage.setItem(STORAGE_KEY, 'true');

        render(<CookieNotice />);

        expect(screen.queryByRole('region', { name: 'Cookie notice' })).toBe(
            null,
        );
    });

    it('remembers the dismissal so it does not come back', async () => {
        render(<CookieNotice />);

        const button = await screen.findByRole('button', { name: 'Got it' });

        act(() => {
            fireEvent.click(button);
        });

        expect(screen.queryByRole('region', { name: 'Cookie notice' })).toBe(
            null,
        );
        expect(localStorage.getItem(STORAGE_KEY)).toBe('true');
    });

    it('points at the cookies section of the privacy policy', async () => {
        render(<CookieNotice />);

        const link = await screen.findByRole('link', {
            name: 'privacy policy',
        });

        expect(link.getAttribute('href')).toBe('/privacy#cookies');
    });

    /**
     * The distinction the component exists to make. Every cookie the site
     * sets is strictly necessary and is set regardless, so an Accept button
     * would be asking for a permission that is not needed and a Reject button
     * would be one we could not honour. If either ever appears here, the
     * cookies genuinely changed — and so must the privacy policy.
     */
    it('offers no accept or reject choice, because there is nothing to choose', async () => {
        render(<CookieNotice />);

        await screen.findByRole('region', { name: 'Cookie notice' });

        const labels = screen
            .getAllByRole('button')
            .map((button) => button.textContent?.toLowerCase() ?? '');

        expect(labels).toEqual(['got it']);
        expect(
            labels.some((label) =>
                /accept|reject|decline|allow|consent/.test(label),
            ),
        ).toBe(false);
    });

    /**
     * Safari's private mode and a browser set to block site data both throw
     * from localStorage rather than returning null. Showing the notice is the
     * safe direction: shown twice is a nuisance, never shown is a disclosure
     * we did not make.
     */
    it('still shows when storage is unavailable', async () => {
        vi.stubGlobal('localStorage', {
            getItem: () => {
                throw new Error('storage disabled');
            },
            setItem: () => {
                throw new Error('storage disabled');
            },
        });

        render(<CookieNotice />);

        expect(
            await screen.findByRole('region', { name: 'Cookie notice' }),
        ).toBeDefined();
    });

    it('can still be dismissed when the write throws', async () => {
        vi.stubGlobal('localStorage', {
            getItem: () => null,
            setItem: () => {
                throw new Error('storage disabled');
            },
        });

        render(<CookieNotice />);

        const button = await screen.findByRole('button', { name: 'Got it' });

        act(() => {
            fireEvent.click(button);
        });

        expect(screen.queryByRole('region', { name: 'Cookie notice' })).toBe(
            null,
        );
    });
});
