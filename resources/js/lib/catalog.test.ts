import { describe, expect, it } from 'vitest';
import {
    completionPercent,
    courseCostSummary,
    isPaidTier,
    missionCostSummary,
    planLabel,
    tierLabel,
} from '@/lib/catalog';

describe('planLabel', () => {
    it('names every plan the server can send', () => {
        expect(planLabel('starter')).toBe('Starter');
        expect(planLabel('pro')).toBe('Pro');
        expect(planLabel('team')).toBe('Team');
    });
});

describe('tierLabel', () => {
    it('calls the starter tier free, because that is what a visitor needs to know', () => {
        expect(tierLabel('starter')).toBe('Free');
    });

    it('names a paid tier after the plan that includes it', () => {
        expect(tierLabel('pro')).toBe('Pro');
        expect(tierLabel('team')).toBe('Team');
    });
});

describe('isPaidTier', () => {
    it('is false only for the tier that costs nothing', () => {
        expect(isPaidTier('starter')).toBe(false);
        expect(isPaidTier('pro')).toBe(true);
        expect(isPaidTier('team')).toBe(true);
    });
});

describe('completionPercent', () => {
    it('reports whole percentages', () => {
        expect(completionPercent(1, 4)).toBe(25);
        expect(completionPercent(2, 3)).toBe(67);
        expect(completionPercent(5, 5)).toBe(100);
    });

    it('is zero for a course with nothing published in it', () => {
        expect(completionPercent(0, 0)).toBe(0);
        expect(completionPercent(3, 0)).toBe(0);
    });

    it('never leaves the 0-100 range, whatever the counts say', () => {
        expect(completionPercent(9, 4)).toBe(100);
        expect(completionPercent(-2, 4)).toBe(0);
        expect(completionPercent(Number.NaN, 4)).toBe(0);
    });
});

describe('missionCostSummary', () => {
    it('says nothing about a course with no published missions', () => {
        expect(missionCostSummary([])).toBeNull();
    });

    it('reports a wholly free course', () => {
        expect(missionCostSummary(['starter', 'starter'])).toBe(
            'Every mission free',
        );
    });

    it('reports a wholly paid course by the plan that includes it', () => {
        expect(missionCostSummary(['pro', 'pro'])).toBe(
            'Every mission with Pro',
        );
    });

    /**
     * The shape three of the seeded courses actually have: a Starter course
     * whose later missions are Pro.
     */
    it('splits a course that is free to start and paid to finish', () => {
        expect(missionCostSummary(['starter', 'pro', 'pro'])).toBe(
            '1 free · 2 with Pro',
        );
    });
});

describe('courseCostSummary', () => {
    it('says so when a course has nothing published in it', () => {
        expect(courseCostSummary(0, 0, null)).toBe('No missions published yet');
    });

    it('counts a wholly free course as free', () => {
        expect(courseCostSummary(5, 5, null)).toBe('5 missions · all free');
    });

    it('names the plan when nothing in the course is free', () => {
        expect(courseCostSummary(0, 4, 'pro')).toBe('4 missions · Pro');
    });

    it('splits a course that is part free and part paid', () => {
        expect(courseCostSummary(5, 9, 'pro')).toBe(
            '9 missions · 5 free, 4 with Pro',
        );
    });

    /**
     * A paid tier on a course whose every mission turned out to be free is a
     * contradiction the card resolves in the pilot's favour rather than
     * charging for nothing.
     */
    it('ignores a plan that gates none of the missions', () => {
        expect(courseCostSummary(3, 3, 'pro')).toBe('3 missions · all free');
    });

    it('speaks of a single mission in the singular', () => {
        expect(courseCostSummary(1, 1, null)).toBe('1 mission · all free');
    });
});
