import { Link } from '@inertiajs/react';
import { MoreHorizontal } from 'lucide-react';
import { Fragment } from 'react';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuGroup,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuShortcut,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { groupActions } from '@/lib/row-actions';
import type { RowAction } from '@/lib/row-actions';
import { cn } from '@/lib/utils';

type RowActionsProps = {
    actions: RowAction[];
    /**
     * Names the trigger for screen readers. Every row renders an identical
     * icon button, so a generic "Actions" leaves a screen-reader user with a
     * list of indistinguishable controls — pass the row's subject, e.g.
     * `` `Actions for ${photo.label}` ``.
     */
    label: string;
    align?: 'start' | 'end';
    className?: string;
};

export function RowActions({
    actions,
    label,
    align = 'end',
    className,
}: RowActionsProps) {
    const enabled = actions.filter((action) => action.disabled !== true);

    // A menu whose every item is unavailable is a dead control; leave the cell
    // empty rather than offering a button that opens onto nothing.
    if (enabled.length === 0) {
        return null;
    }

    const groups = groupActions(actions);

    return (
        <DropdownMenu>
            <DropdownMenuTrigger asChild>
                <Button
                    variant="ghost"
                    size="icon-sm"
                    className={cn('text-muted-foreground', className)}
                >
                    <MoreHorizontal aria-hidden="true" />
                    <span className="sr-only">{label}</span>
                </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align={align} className="w-44">
                {groups.map((group, index) => (
                    <Fragment key={group[0].label}>
                        {index > 0 && <DropdownMenuSeparator />}
                        <DropdownMenuGroup>
                            {group.map((action) => (
                                <DropdownMenuItem
                                    key={action.label}
                                    variant={action.variant}
                                    disabled={action.disabled}
                                    onSelect={action.onSelect}
                                    asChild={action.href !== undefined}
                                >
                                    {action.href !== undefined ? (
                                        <Link href={action.href}>
                                            {action.icon}
                                            {action.label}
                                            {action.shortcut && (
                                                <DropdownMenuShortcut>
                                                    {action.shortcut}
                                                </DropdownMenuShortcut>
                                            )}
                                        </Link>
                                    ) : (
                                        <>
                                            {action.icon}
                                            {action.label}
                                            {action.shortcut && (
                                                <DropdownMenuShortcut>
                                                    {action.shortcut}
                                                </DropdownMenuShortcut>
                                            )}
                                        </>
                                    )}
                                </DropdownMenuItem>
                            ))}
                        </DropdownMenuGroup>
                    </Fragment>
                ))}
            </DropdownMenuContent>
        </DropdownMenu>
    );
}
