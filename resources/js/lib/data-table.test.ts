import { describe, expect, it } from 'vitest';
import {
    ariaSortFor,
    compareValues,
    cycleSort,
    sortHint,
    sortRows,
} from '@/lib/data-table';
import type { ColumnDef } from '@/lib/data-table';

type Pilot = { name: string; points: number | null };

const byName: ColumnDef<Pilot> = {
    id: 'name',
    header: 'Pilot',
    cell: (pilot) => pilot.name,
    sortValue: (pilot) => pilot.name,
};

const byPoints: ColumnDef<Pilot> = {
    id: 'points',
    header: 'Points',
    cell: (pilot) => pilot.points,
    sortValue: (pilot) => pilot.points,
};

const names = (pilots: Pilot[]) => pilots.map((pilot) => pilot.name);

describe('compareValues', () => {
    it('compares numbers numerically rather than as text', () => {
        expect(compareValues(9, 10)).toBeLessThan(0);
    });

    it('compares numbered strings the way a reader reads them', () => {
        // The whole reason localeCompare gets `numeric: true`: plain string
        // ordering puts "Mission 10" before "Mission 2".
        expect(compareValues('Mission 2', 'Mission 10')).toBeLessThan(0);
    });

    it('ignores case and accents so names land where they are looked for', () => {
        expect(compareValues('ámelie', 'Amelie')).toBe(0);
    });

    it('orders dates by instant', () => {
        expect(
            compareValues(new Date('2026-01-01'), new Date('2026-06-01')),
        ).toBeLessThan(0);
    });
});

describe('sortRows', () => {
    const pilots: Pilot[] = [
        { name: 'Chidi', points: 30 },
        { name: 'Ama', points: 10 },
        { name: 'Boubacar', points: 20 },
    ];

    it('sorts ascending and descending', () => {
        expect(names(sortRows(pilots, byPoints, 'asc'))).toEqual([
            'Ama',
            'Boubacar',
            'Chidi',
        ]);
        expect(names(sortRows(pilots, byPoints, 'desc'))).toEqual([
            'Chidi',
            'Boubacar',
            'Ama',
        ]);
    });

    it('never mutates the rows it was given', () => {
        // Inertia page props are shared with the history cache; sorting in
        // place corrupts the entry the back button restores.
        const original = [...pilots];

        sortRows(pilots, byPoints, 'desc');

        expect(pilots).toEqual(original);
    });

    it('sinks missing values to the bottom in both directions', () => {
        const withGaps: Pilot[] = [
            { name: 'Ama', points: null },
            { name: 'Boubacar', points: 20 },
            { name: 'Chidi', points: 10 },
        ];

        expect(names(sortRows(withGaps, byPoints, 'asc'))).toEqual([
            'Chidi',
            'Boubacar',
            'Ama',
        ]);
        expect(names(sortRows(withGaps, byPoints, 'desc'))).toEqual([
            'Boubacar',
            'Chidi',
            'Ama',
        ]);
    });

    it('holds ties in their incoming order', () => {
        const tied: Pilot[] = [
            { name: 'Chidi', points: 10 },
            { name: 'Ama', points: 10 },
            { name: 'Boubacar', points: 10 },
        ];

        expect(names(sortRows(tied, byPoints, 'asc'))).toEqual([
            'Chidi',
            'Ama',
            'Boubacar',
        ]);
        expect(names(sortRows(tied, byPoints, 'desc'))).toEqual([
            'Chidi',
            'Ama',
            'Boubacar',
        ]);
    });

    it('leaves rows alone for a column with no sortValue', () => {
        const plain: ColumnDef<Pilot> = {
            id: 'name',
            header: 'Pilot',
            cell: (pilot) => pilot.name,
        };

        expect(names(sortRows(pilots, plain, 'asc'))).toEqual(names(pilots));
        expect(names(sortRows(pilots, undefined, 'asc'))).toEqual(
            names(pilots),
        );
    });

    it('sorts text through the column it was told to use', () => {
        expect(names(sortRows(pilots, byName, 'asc'))).toEqual([
            'Ama',
            'Boubacar',
            'Chidi',
        ]);
    });
});

describe('cycleSort', () => {
    it('starts a new column ascending', () => {
        expect(cycleSort(null, 'points')).toEqual({
            columnId: 'points',
            direction: 'asc',
        });
    });

    it('goes ascending, descending, then back to the natural order', () => {
        const first = cycleSort(null, 'points');
        const second = cycleSort(first, 'points');

        expect(second).toEqual({ columnId: 'points', direction: 'desc' });
        expect(cycleSort(second, 'points')).toBeNull();
    });

    it('restarts when a different column is pressed', () => {
        expect(
            cycleSort({ columnId: 'points', direction: 'desc' }, 'name'),
        ).toEqual({ columnId: 'name', direction: 'asc' });
    });
});

describe('ariaSortFor', () => {
    it('reports only the sorted column', () => {
        const sort = { columnId: 'points', direction: 'asc' } as const;

        expect(ariaSortFor('points', sort)).toBe('ascending');
        expect(ariaSortFor('name', sort)).toBe('none');
        expect(ariaSortFor('points', null)).toBe('none');
    });
});

describe('sortHint', () => {
    it('announces what pressing the header will do next', () => {
        expect(sortHint('points', null)).toBe('Sort ascending');
        expect(
            sortHint('points', { columnId: 'points', direction: 'asc' }),
        ).toBe('Sort descending');
        expect(
            sortHint('points', { columnId: 'points', direction: 'desc' }),
        ).toBe('Remove sorting');
    });
});
