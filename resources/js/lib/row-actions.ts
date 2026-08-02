import type { InertiaLinkProps } from '@inertiajs/react';
import type { ReactNode } from 'react';

export type RowAction = {
    /** Also the React key, so it must be unique within one menu. */
    label: string;
    icon?: ReactNode;
    /**
     * Fires when the item is chosen. If it opens a dialog, keep that dialog
     * mounted *outside* this menu and flip state here — a dialog rendered
     * inside the menu unmounts with it on select and never appears.
     */
    onSelect?: () => void;
    /** Renders the item as an Inertia `Link` instead of a button. */
    href?: NonNullable<InertiaLinkProps['href']>;
    variant?: 'default' | 'destructive';
    disabled?: boolean;
    /** Displayed right-aligned; purely a hint, binding the key is on you. */
    shortcut?: string;
    /**
     * Actions sharing a name render together, separated from the next group.
     * Ungrouped actions collect into one leading group.
     */
    group?: string;
};

/**
 * Bucket actions by `group`, keeping first-appearance order.
 *
 * Order comes from the array, not from sorting group names: a destructive
 * action has to stay at the bottom regardless of what its group is called.
 */
export function groupActions(actions: readonly RowAction[]): RowAction[][] {
    const groups = new Map<string, RowAction[]>();

    for (const action of actions) {
        const key = action.group ?? '';
        const existing = groups.get(key);

        if (existing) {
            existing.push(action);

            continue;
        }

        groups.set(key, [action]);
    }

    return [...groups.values()];
}
