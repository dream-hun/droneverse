import { describe, expect, it } from 'vitest';
import { bringsOwnChrome } from '@/lib/page-chrome';

describe('bringsOwnChrome', () => {
    it('claims the public pages that draw their own header and footer', () => {
        expect(bringsOwnChrome('welcome')).toBe(true);
        expect(bringsOwnChrome('pricing')).toBe(true);
        expect(bringsOwnChrome('legal/terms')).toBe(true);
        expect(bringsOwnChrome('legal/privacy')).toBe(true);
        expect(bringsOwnChrome('docs')).toBe(true);
    });

    /**
     * The catalog, a course and its guide are one public section, and the
     * cockpit a mission opens is not part of it.
     */
    it('claims the whole course subtree', () => {
        expect(bringsOwnChrome('courses/index')).toBe(true);
        expect(bringsOwnChrome('courses/show')).toBe(true);
        expect(bringsOwnChrome('courses/docs')).toBe(true);
        expect(bringsOwnChrome('challenges/show')).toBe(false);
        expect(bringsOwnChrome('quizzes/show')).toBe(false);
    });

    /**
     * The one signed-in page that draws its own chrome: a buyer lands on it
     * out of a checkout overlay, and it is the end of a purchase rather than a
     * screen in the product.
     */
    it('claims the post-checkout confirmation', () => {
        expect(bringsOwnChrome('subscription/thank-you')).toBe(true);
    });

    it('leaves the signed-in pages to the app shell', () => {
        expect(bringsOwnChrome('dashboard')).toBe(false);
        expect(bringsOwnChrome('leaderboard')).toBe(false);
        expect(bringsOwnChrome('analytics')).toBe(false);
        expect(bringsOwnChrome('settings/profile')).toBe(false);
        expect(bringsOwnChrome('auth/login')).toBe(false);
    });

    /**
     * The prefixes are matched at the start, not anywhere: a signed-in page
     * that merely mentions a public one in its name still belongs to the app
     * shell.
     */
    it('matches on the leading segment rather than anywhere in the name', () => {
        expect(bringsOwnChrome('settings/courses')).toBe(false);
        expect(bringsOwnChrome('settings/docs')).toBe(false);
        expect(bringsOwnChrome('settings/pricing')).toBe(false);
        expect(bringsOwnChrome('photos/legal/terms')).toBe(false);
        expect(bringsOwnChrome('settings/subscription/thank-you')).toBe(false);
    });
});
