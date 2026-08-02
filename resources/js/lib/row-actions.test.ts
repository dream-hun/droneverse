import { describe, expect, it } from 'vitest';
import { groupActions } from '@/lib/row-actions';
import type { RowAction } from '@/lib/row-actions';

const labels = (groups: RowAction[][]) =>
    groups.map((group) => group.map((action) => action.label));

describe('groupActions', () => {
    it('collects ungrouped actions into a single group', () => {
        expect(
            labels(groupActions([{ label: 'Edit' }, { label: 'Duplicate' }])),
        ).toEqual([['Edit', 'Duplicate']]);
    });

    it('keeps the array order rather than sorting group names', () => {
        // A destructive action sits at the bottom because it was listed last,
        // not because "danger" sorts after "manage".
        expect(
            labels(
                groupActions([
                    { label: 'Open', group: 'view' },
                    { label: 'Delete', group: 'danger' },
                    { label: 'Share', group: 'view' },
                ]),
            ),
        ).toEqual([['Open', 'Share'], ['Delete']]);
    });

    it('separates ungrouped actions from grouped ones', () => {
        expect(
            labels(
                groupActions([
                    { label: 'Edit' },
                    { label: 'Delete', group: 'danger' },
                ]),
            ),
        ).toEqual([['Edit'], ['Delete']]);
    });

    it('returns nothing for no actions', () => {
        expect(groupActions([])).toEqual([]);
    });
});
