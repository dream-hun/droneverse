import { describe, expect, it } from 'vitest';
import { pageTitle } from '@/lib/page-title';

describe('pageTitle', () => {
    it('puts the site name after a page title', () => {
        expect(pageTitle('Dashboard', 'DroneVerse')).toBe(
            'Dashboard - DroneVerse',
        );
    });

    it('falls back to the site name when the page sets no title', () => {
        expect(pageTitle('', 'DroneVerse')).toBe('DroneVerse');
    });

    /**
     * The server brands the titles it writes into the page's head, and
     * Inertia passes them through this callback on their way to the document.
     */
    it('leaves a title that already names the site alone', () => {
        expect(pageTitle('Pricing - DroneVerse', 'DroneVerse')).toBe(
            'Pricing - DroneVerse',
        );
    });
});
