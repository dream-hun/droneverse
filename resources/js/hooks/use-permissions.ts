import { usePage } from '@inertiajs/react';
import type { AdminPermissionValue } from '@/types/auth';

export type UsePermissionsReturn = {
    permissions: AdminPermissionValue[];
    /** Whether the viewer holds a given admin permission. */
    can: (permission: AdminPermissionValue) => boolean;
};

/**
 * Read the admin permissions the server resolved for this request.
 *
 * The same contract as `usePlan`: use it to decide what to show — a sidebar
 * section, a row action — and never to decide what is allowed. Every admin
 * route asks the Gate for itself, so a control shown here by mistake is a 403
 * rather than a breach.
 */
export function usePermissions(): UsePermissionsReturn {
    const { auth } = usePage().props;

    const permissions = auth?.permissions ?? [];

    return {
        permissions,
        can: (permission: AdminPermissionValue) =>
            permissions.includes(permission),
    };
}
