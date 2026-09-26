import { router } from '@inertiajs/react';
import { Eye, MailCheck, Pencil, Trash2 } from 'lucide-react';
import { useState } from 'react';
import type { ReactNode } from 'react';
import { UserFormDialog } from '@/components/admin/user-form-dialog';
import { ConfirmDialog } from '@/components/confirm-dialog';
import { visitAsPromise } from '@/lib/inertia-promise';
import type { RowAction } from '@/lib/row-actions';
import { destroy, show } from '@/routes/admin/users';
import { store as verify } from '@/routes/admin/users/verification';
import type { AdminUser, UserFormOptions } from '@/types/admin';

type UseUserActionsReturn = {
    /** The grouped menu for one account, trimmed to what the viewer may do. */
    actionsFor: (
        user: AdminUser,
        options?: { includeView?: boolean },
    ) => RowAction[];
    /** The edit and delete dialogs; render once, outside any menu. */
    dialogs: ReactNode;
};

/**
 * What staff can do to an account, shared by the list and the account page.
 *
 * Each action is offered only when the server's policy — sent down as the
 * row's `can` — would allow it. The dialogs live here rather than in the menu
 * because a Radix menu unmounts its items on select and would take a dialog
 * rendered inside it along.
 */
export function useUserActions(options: UserFormOptions): UseUserActionsReturn {
    const [editing, setEditing] = useState<AdminUser | null>(null);
    const [deleting, setDeleting] = useState<AdminUser | null>(null);

    function actionsFor(
        user: AdminUser,
        { includeView = true }: { includeView?: boolean } = {},
    ): RowAction[] {
        return [
            ...(includeView
                ? [
                      {
                          label: 'View account',
                          icon: <Eye />,
                          href: show(user.uuid),
                      },
                  ]
                : []),
            ...(user.can.update
                ? [
                      {
                          label: 'Edit',
                          icon: <Pencil />,
                          onSelect: () => setEditing(user),
                      },
                  ]
                : []),
            ...(user.can.update && !user.emailVerified
                ? [
                      {
                          label: 'Mark email verified',
                          icon: <MailCheck />,
                          onSelect: () =>
                              router.post(
                                  verify.url(user.uuid),
                                  {},
                                  { preserveScroll: true },
                              ),
                      },
                  ]
                : []),
            ...(user.can.delete
                ? [
                      {
                          label: 'Delete account',
                          icon: <Trash2 />,
                          variant: 'destructive' as const,
                          group: 'danger',
                          onSelect: () => setDeleting(user),
                      },
                  ]
                : []),
        ];
    }

    const dialogs = (
        <>
            {editing && (
                <UserFormDialog
                    user={editing}
                    options={options}
                    open
                    onOpenChange={(open) => !open && setEditing(null)}
                />
            )}

            <ConfirmDialog
                open={deleting !== null}
                onOpenChange={(open) => !open && setDeleting(null)}
                destructive
                title={`Delete ${deleting?.name ?? 'this account'}?`}
                description="Their progress, runs, quiz attempts and photos go with the account, for good. An account Creem is still billing is refused — cancel the subscription first."
                confirmationText={deleting?.email}
                confirmLabel="Delete account"
                pendingLabel="Deleting…"
                onConfirm={() =>
                    visitAsPromise(
                        (visitOptions) =>
                            router.delete(
                                destroy.url(deleting?.uuid ?? ''),
                                visitOptions,
                            ),
                        {},
                        'The account was not deleted',
                    )
                }
            />
        </>
    );

    return { actionsFor, dialogs };
}
