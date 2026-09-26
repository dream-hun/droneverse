import { Link } from '@inertiajs/react';
import { usePermissions } from '@/hooks/use-permissions';
import { show as showUser } from '@/routes/admin/users';
import type { PilotRef } from '@/types/admin';

/**
 * A pilot's name and address, linked to their admin page for staff who can
 * open it.
 *
 * Finance staff see who paid without holding `manage_users`, and a link that
 * only ever 403s teaches them the screen lies, so for them it is plain text.
 */
export function PilotLink({ pilot }: { pilot: PilotRef | null }) {
    const { can } = usePermissions();

    if (!pilot) {
        return <span className="text-muted-foreground">Not an account</span>;
    }

    return (
        <div className="min-w-0">
            {can('manage_users') ? (
                <Link
                    href={showUser(pilot.uuid)}
                    className="block truncate font-medium hover:underline"
                >
                    {pilot.name}
                </Link>
            ) : (
                <span className="block truncate font-medium">{pilot.name}</span>
            )}
            <span className="block truncate text-muted-foreground">
                {pilot.email}
            </span>
        </div>
    );
}
