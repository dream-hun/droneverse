import { describe, expect, it } from 'vitest';
import {
    MISSING,
    formatBytes,
    formatCount,
    formatDuration,
    formatPercent,
    formatRelative,
    humanizeStatus,
    slugify,
} from '@/lib/admin-format';

describe('missing values', () => {
    it('render as a dash rather than a zero', () => {
        // The contract the whole module exists for: on a monitoring screen a
        // count nobody could take must not read as "none".
        expect(formatBytes(null)).toBe(MISSING);
        expect(formatCount(undefined)).toBe(MISSING);
        expect(formatPercent(null)).toBe(MISSING);
        expect(formatDuration(Number.NaN)).toBe(MISSING);
        expect(formatRelative('not a date')).toBe(MISSING);
    });

    it('keep a real zero as zero', () => {
        expect(formatCount(0)).toBe('0');
        expect(formatPercent(0)).toBe('0%');
        expect(formatBytes(0)).toBe('0 B');
    });
});

describe('formatBytes', () => {
    it('steps up binary units and stops at terabytes', () => {
        expect(formatBytes(512)).toBe('512 B');
        expect(formatBytes(1536)).toBe('1.5 KB');
        expect(formatBytes(5 * 1024 ** 3)).toBe('5.0 GB');
        expect(formatBytes(3 * 1024 ** 5)).toBe('3072.0 TB');
    });
});

describe('formatPercent', () => {
    it('rounds a ratio to a whole percentage', () => {
        expect(formatPercent(0.873)).toBe('87%');
        expect(formatPercent(1)).toBe('100%');
    });
});

describe('formatDuration', () => {
    it('picks the coarsest unit that still reads as a duration', () => {
        expect(formatDuration(42)).toBe('42s');
        expect(formatDuration(600)).toBe('10m');
        expect(formatDuration(5400)).toBe('1.5h');
        expect(formatDuration(172800)).toBe('2.0d');
    });
});

describe('formatRelative', () => {
    const now = new Date('2026-09-25T12:00:00Z');

    it('calls the last few seconds "just now"', () => {
        expect(formatRelative('2026-09-25T11:59:30Z', now)).toBe('just now');
    });

    it('walks up to the unit that keeps the number small', () => {
        expect(formatRelative('2026-09-25T11:55:00Z', now)).toMatch(
            /5 minutes ago/,
        );
        expect(formatRelative('2026-09-25T09:00:00Z', now)).toMatch(
            /3 hours ago/,
        );
        expect(formatRelative('2026-09-22T12:00:00Z', now)).toMatch(
            /3 days ago/,
        );
    });
});

describe('humanizeStatus', () => {
    it("spells Creem's wire strings for a person", () => {
        expect(humanizeStatus('scheduled_cancel')).toBe('Scheduled cancel');
        expect(humanizeStatus('active')).toBe('Active');
    });
});

describe('slugify', () => {
    it('produces the shape the server accepts', () => {
        expect(slugify('Hover & Land')).toBe('hover-land');
        expect(slugify('  Crème Brûlée 2 ')).toBe('creme-brulee-2');
        expect(slugify('--Already--hyphenated--')).toBe('already-hyphenated');
    });

    it('matches the server rule for anything it returns', () => {
        // The same pattern SaveCourseRequest and SaveChallengeRequest apply.
        const rule = /^[a-z0-9]+(?:-[a-z0-9]+)*$/;

        for (const title of [
            'Drone Basics',
            'Mission #10: Night Ops!',
            'Äpfel',
        ]) {
            expect(slugify(title)).toMatch(rule);
        }
    });
});
