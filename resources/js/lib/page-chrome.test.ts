import { describe, expect, it } from 'vitest';
import { bringsOwnChrome } from '@/lib/page-chrome';

describe('bringsOwnChrome', () => {
    it('claims the public pages that draw their own header and footer', () => {
        expect(bringsOwnChrome('welcome')).toBe(true);
        expect(bringsOwnChrome('pricing')).toBe(true);
        expect(bringsOwnChrome('legal/terms')).toBe(true);
        expect(bringsOwnChrome('legal/privacy')).toBe(true);
    });

    it('leaves the signed-in pages to the app shell', () => {
        expect(bringsOwnChrome('dashboard')).toBe(false);
        expect(bringsOwnChrome('leaderboard')).toBe(false);
        expect(bringsOwnChrome('analytics')).toBe(false);
        expect(bringsOwnChrome('courses/show')).toBe(false);
        expect(bringsOwnChrome('settings/profile')).toBe(false);
        expect(bringsOwnChrome('auth/login')).toBe(false);
    });

    /**
     * The prefix is matched at the start, not anywhere: a signed-in page that
     * merely mentions a public one in its name still belongs to the app shell.
     */
    it('matches on the leading segment rather than anywhere in the name', () => {
        expect(bringsOwnChrome('courses/legal/terms')).toBe(false);
        expect(bringsOwnChrome('settings/pricing')).toBe(false);
    });
});
