import { Head, router } from '@inertiajs/react';
import { KeyRound, Pencil, Plus, Trash2 } from 'lucide-react';
import { useState } from 'react';
import type { ReactNode } from 'react';
import { ConfirmDialog } from '@/components/confirm-dialog';
import { DataTable } from '@/components/data-table';
import { FormDialog } from '@/components/form-dialog';
import InputError from '@/components/input-error';
import { PageHeader } from '@/components/page-header';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { formatCount } from '@/lib/admin-format';
import type { ColumnDef } from '@/lib/data-table';
import { visitAsPromise } from '@/lib/inertia-promise';
import type { RowAction } from '@/lib/row-actions';
import { dashboard } from '@/routes/admin';
import { destroy, index, store, update } from '@/routes/admin/roles';
import type { AdminRole, PermissionOption } from '@/types/admin';
import type { AdminPermissionValue } from '@/types/auth';

type RolesIndexProps = {
    roles: AdminRole[];
    permissions: PermissionOption[];
    /** The permissions the viewer holds, and so may grant. */
    grantable: AdminPermissionValue[];
};

type RoleFormDialogProps = {
    role?: AdminRole;
    permissions: PermissionOption[];
    grantable: AdminPermissionValue[];
    trigger?: ReactNode;
    open?: boolean;
    onOpenChange?: (open: boolean) => void;
};

/**
 * Create a role or edit one: a name and a set of permissions.
 *
 * Unticking every box is sent as an empty list rather than as nothing, so a
 * role can be emptied as well as filled. A permission the viewer does not hold
 * is shown but cannot be ticked — the server refuses it either way.
 */
function RoleFormDialog({
    role,
    permissions,
    grantable,
    trigger,
    open,
    onOpenChange,
}: RoleFormDialogProps) {
    const editing = role !== undefined;

    return (
        <FormDialog
            {...(editing ? update.form(role.uuid) : store.form())}
            key={role?.uuid ?? 'new'}
            trigger={trigger}
            open={open}
            onOpenChange={onOpenChange}
            title={editing ? `Edit ${role.name}` : 'New role'}
            description="Everyone holding the role gains or loses the difference the moment you save."
            submitLabel={editing ? 'Save role' : 'Create role'}
            pendingLabel="Saving…"
            resetOnSuccess={!editing}
            transform={(data) => ({
                ...data,
                permissions: data.permissions ?? [],
            })}
        >
            {({ errors }) => (
                <div className="grid gap-4">
                    <div className="grid gap-2">
                        <Label htmlFor="role-name">Name</Label>
                        <Input
                            id="role-name"
                            name="name"
                            defaultValue={role?.name}
                            placeholder="content-manager"
                            required
                            autoComplete="off"
                        />
                        <InputError message={errors.name} />
                    </div>

                    <fieldset className="grid gap-3">
                        <legend className="mb-1 text-sm font-medium">
                            Permissions
                        </legend>
                        {permissions.map((permission) => {
                            const held = grantable.includes(permission.value);

                            return (
                                <div
                                    key={permission.value}
                                    className="flex items-start gap-2"
                                >
                                    <Checkbox
                                        id={`permission-${permission.value}`}
                                        name="permissions[]"
                                        value={permission.value}
                                        defaultChecked={role?.permissions.includes(
                                            permission.value,
                                        )}
                                        disabled={!held}
                                        className="mt-0.5"
                                    />
                                    <div
                                        className={
                                            held
                                                ? 'grid gap-0.5'
                                                : 'grid gap-0.5 opacity-60'
                                        }
                                    >
                                        <Label
                                            htmlFor={`permission-${permission.value}`}
                                        >
                                            {permission.label}
                                            {!held && ' (you do not hold this)'}
                                        </Label>
                                        <p className="text-xs text-muted-foreground">
                                            {permission.description}
                                        </p>
                                    </div>
                                </div>
                            );
                        })}
                        <InputError message={errors.permissions} />
                    </fieldset>
                </div>
            )}
        </FormDialog>
    );
}

export default function RolesIndex({
    roles,
    permissions,
    grantable,
}: RolesIndexProps) {
    const [editing, setEditing] = useState<AdminRole | null>(null);
    const [deleting, setDeleting] = useState<AdminRole | null>(null);

    const labels = new Map(
        permissions.map((permission) => [permission.value, permission.label]),
    );

    const columns: ColumnDef<AdminRole>[] = [
        {
            id: 'name',
            header: 'Role',
            cell: (role) => (
                <div className="flex items-center gap-2">
                    <span className="font-medium">{role.name}</span>
                    {role.isAdmin && <Badge>Everything</Badge>}
                </div>
            ),
        },
        {
            id: 'permissions',
            header: 'Can',
            cell: (role) =>
                role.isAdmin ? (
                    <span className="text-muted-foreground">
                        Every permission, always — including ones added later
                    </span>
                ) : role.permissions.length > 0 ? (
                    <div className="flex flex-wrap gap-1">
                        {role.permissions.map((permission) => (
                            <Badge key={permission} variant="outline">
                                {labels.get(permission) ?? permission}
                            </Badge>
                        ))}
                    </div>
                ) : (
                    <span className="text-muted-foreground">Nothing yet</span>
                ),
        },
        {
            id: 'users',
            header: 'Held by',
            align: 'end',
            cell: (role) => formatCount(role.users),
        },
    ];

    function actionsFor(role: AdminRole): RowAction[] {
        return [
            ...(role.can.update
                ? [
                      {
                          label: 'Edit',
                          icon: <Pencil />,
                          onSelect: () => setEditing(role),
                      },
                  ]
                : []),
            ...(role.can.delete
                ? [
                      {
                          label: 'Delete role',
                          icon: <Trash2 />,
                          variant: 'destructive' as const,
                          group: 'danger',
                          onSelect: () => setDeleting(role),
                      },
                  ]
                : []),
        ];
    }

    return (
        <>
            <Head title="Roles" />

            <div className="space-y-6 p-4">
                <PageHeader
                    title="Roles"
                    description="What each kind of staff member may do in the admin area. The admin role holds everything and cannot be changed."
                    actions={
                        <RoleFormDialog
                            permissions={permissions}
                            grantable={grantable}
                            trigger={
                                <Button>
                                    <Plus />
                                    New role
                                </Button>
                            }
                        />
                    }
                />

                <div className="flex items-start gap-2 rounded-xl border bg-card p-3 text-xs text-muted-foreground">
                    <KeyRound
                        aria-hidden="true"
                        className="mt-0.5 size-4 shrink-0"
                    />
                    <p>
                        You can only grant permissions you hold yourself, and
                        only edit or hand out roles that grant nothing beyond
                        them. Managing roles shares what you have; it never gets
                        you more.
                    </p>
                </div>

                <DataTable
                    caption="Staff roles"
                    columns={columns}
                    rows={roles}
                    rowKey={(role) => role.uuid}
                    actions={actionsFor}
                    actionsLabel={(role) => `Actions for ${role.name}`}
                />
            </div>

            {editing && (
                <RoleFormDialog
                    role={editing}
                    grantable={grantable}
                    permissions={permissions}
                    open
                    onOpenChange={(open) => !open && setEditing(null)}
                />
            )}

            <ConfirmDialog
                open={deleting !== null}
                onOpenChange={(open) => !open && setDeleting(null)}
                destructive
                title={`Delete the ${deleting?.name ?? ''} role?`}
                description={`${formatCount(deleting?.users ?? 0)} ${deleting?.users === 1 ? 'person holds' : 'people hold'} it. They keep their accounts and lose whatever this role let them do.`}
                confirmLabel="Delete role"
                pendingLabel="Deleting…"
                onConfirm={() =>
                    visitAsPromise(
                        (options) =>
                            router.delete(
                                destroy.url(deleting?.uuid ?? ''),
                                options,
                            ),
                        {},
                        'The role was not deleted',
                    )
                }
            />
        </>
    );
}

RolesIndex.layout = {
    breadcrumbs: [
        { title: 'Admin', href: dashboard() },
        { title: 'Roles', href: index() },
    ],
};
