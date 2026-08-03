import { describe, expect, it } from 'vitest';
import { gridColumnsClass, skeletonItemCount } from '@/lib/card-grid';
import type { GridColumns } from '@/lib/card-grid';

const ALL_COLUMNS: GridColumns[] = [1, 2, 3, 4];

describe('gridColumnsClass', () => {
    it('starts every layout at a single column', () => {
        for (const columns of ALL_COLUMNS) {
            expect(gridColumnsClass(columns)).toContain('grid-cols-1');
        }
    });

    it('adds one breakpoint per extra column', () => {
        expect(gridColumnsClass(1)).toBe('grid-cols-1');
        expect(gridColumnsClass(2)).toBe('grid-cols-1 sm:grid-cols-2');
        expect(gridColumnsClass(3)).toBe(
            'grid-cols-1 sm:grid-cols-2 lg:grid-cols-3',
        );
        expect(gridColumnsClass(4)).toBe(
            'grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4',
        );
    });

    it('emits literal class names Tailwind can find by scanning', () => {
        // The guard against reintroducing `grid-cols-${columns}`: an
        // interpolated name passes tests that only count columns, then ships
        // as one column because the extractor never saw the string.
        for (const columns of ALL_COLUMNS) {
            expect(gridColumnsClass(columns)).toMatch(
                /^grid-cols-1( (sm|lg|xl):grid-cols-[234])*$/,
            );
        }
    });
});

describe('skeletonItemCount', () => {
    it('leaves counts that already fill whole rows alone', () => {
        expect(skeletonItemCount(3, 6)).toBe(6);
        expect(skeletonItemCount(4, 8)).toBe(8);
        expect(skeletonItemCount(1, 5)).toBe(5);
    });

    it('rounds up so the last row is not ragged', () => {
        expect(skeletonItemCount(3, 4)).toBe(6);
        expect(skeletonItemCount(4, 5)).toBe(8);
        expect(skeletonItemCount(2, 3)).toBe(4);
    });

    it('always fills at least one row', () => {
        for (const columns of ALL_COLUMNS) {
            expect(skeletonItemCount(columns, 0)).toBe(columns);
            expect(skeletonItemCount(columns, -2)).toBe(columns);
        }
    });

    it('falls back to one row rather than rendering nothing on bad input', () => {
        // A count derived from arithmetic on a missing prop should degrade to
        // a visible placeholder, not an empty box that looks like a finished
        // empty state.
        expect(skeletonItemCount(3, Number.NaN)).toBe(3);
        expect(skeletonItemCount(2, Number.POSITIVE_INFINITY)).toBe(2);
    });

    it('rounds fractional counts up to a whole row', () => {
        expect(skeletonItemCount(3, 2.5)).toBe(3);
    });
});
