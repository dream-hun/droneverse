import type { ReactNode } from 'react';
import { FormDialog } from '@/components/form-dialog';
import InputError from '@/components/input-error';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { store, update } from '@/routes/admin/users';
import type { AdminUser, UserFormOptions } from '@/types/admin';

/** Radix refuses an empty item value, so "no override" needs a name. */
const NO_OVERRIDE = '__none__';

type UserFormDialogProps = {
    /** The account being edited; omit to open an account instead. */
    user?: AdminUser;
    options: UserFormOptions;
    trigger?: ReactNode;
    open?: boolean;
    onOpenChange?: (open: boolean) => void;
};

/**
 * Open an account, or edit one, in a modal.
 *
 * The roles field appears only for staff who may hand roles out, and when it
 * is absent nothing about roles is sent — the server reads an absent field as
 * "leave them alone", so a support agent saving a name cannot strip roles by
 * accident. When it is present, unticking every box is sent as an empty list,
 * which is how roles are taken away.
 */
export function UserFormDialog({
    user,
    options,
    trigger,
    open,
    onOpenChange,
}: UserFormDialogProps) {
    const editing = user !== undefined;
    const form = editing ? update.form(user.uuid) : store.form();

    return (
        <FormDialog
            {...form}
            key={user?.uuid ?? 'new'}
            trigger={trigger}
            open={open}
            onOpenChange={onOpenChange}
            title={editing ? `Edit ${user.name}` : 'New account'}
            description={
                editing
                    ? 'Changing the address marks it unverified until the pilot confirms it, as it does when they change it themselves.'
                    : 'For somebody who cannot sign up themselves. The address stays unverified unless you vouch for it.'
            }
            submitLabel={editing ? 'Save changes' : 'Create account'}
            pendingLabel={editing ? 'Saving…' : 'Creating…'}
            resetOnSuccess={!editing}
            transform={(data) => ({
                ...data,
                plan_override:
                    data.plan_override === NO_OVERRIDE
                        ? null
                        : data.plan_override,
                ...(options.can.assignRoles ? { roles: data.roles ?? [] } : {}),
            })}
        >
            {({ errors }) => (
                <div className="grid gap-4">
                    <div className="grid gap-2">
                        <Label htmlFor="user-name">Name</Label>
                        <Input
                            id="user-name"
                            name="name"
                            defaultValue={user?.name}
                            required
                            autoComplete="off"
                        />
                        <InputError message={errors.name} />
                    </div>

                    <div className="grid gap-2">
                        <Label htmlFor="user-email">Email address</Label>
                        <Input
                            id="user-email"
                            name="email"
                            type="email"
                            defaultValue={user?.email}
                            required
                            autoComplete="off"
                        />
                        <InputError message={errors.email} />
                    </div>

                    {!editing && (
                        <>
                            <div className="grid gap-2 sm:grid-cols-2">
                                <div className="grid gap-2">
                                    <Label htmlFor="user-password">
                                        Password
                                    </Label>
                                    <Input
                                        id="user-password"
                                        name="password"
                                        type="password"
                                        required
                                        autoComplete="new-password"
                                    />
                                </div>
                                <div className="grid gap-2">
                                    <Label htmlFor="user-password-confirmation">
                                        Confirm password
                                    </Label>
                                    <Input
                                        id="user-password-confirmation"
                                        name="password_confirmation"
                                        type="password"
                                        required
                                        autoComplete="new-password"
                                    />
                                </div>
                            </div>
                            <InputError message={errors.password} />

                            <div className="flex items-center gap-2">
                                <Checkbox
                                    id="user-email-verified"
                                    name="email_verified"
                                    value="1"
                                />
                                <Label htmlFor="user-email-verified">
                                    I have confirmed this address belongs to
                                    them
                                </Label>
                            </div>
                        </>
                    )}

                    <div className="grid gap-2">
                        <Label htmlFor="user-plan">Plan set by hand</Label>
                        <Select
                            name="plan_override"
                            defaultValue={user?.planOverride ?? NO_OVERRIDE}
                        >
                            <SelectTrigger id="user-plan" className="w-full">
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value={NO_OVERRIDE}>
                                    None — follow their subscription
                                </SelectItem>
                                {options.plans.map((plan) => (
                                    <SelectItem
                                        key={plan.value}
                                        value={plan.value}
                                    >
                                        {plan.label}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                        <p className="text-xs text-muted-foreground">
                            For comped, staff and academic accounts. It outranks
                            anything billing says.
                        </p>
                        <InputError message={errors.plan_override} />
                    </div>

                    {options.can.assignRoles && options.roles.length > 0 && (
                        <fieldset className="grid gap-2">
                            <legend className="mb-1 text-sm font-medium">
                                Staff roles
                            </legend>
                            {options.roles.map((role) => (
                                <div
                                    key={role.name}
                                    className="flex items-center gap-2"
                                >
                                    <Checkbox
                                        id={`user-role-${role.name}`}
                                        name="roles[]"
                                        value={role.name}
                                        defaultChecked={user?.roles.includes(
                                            role.name,
                                        )}
                                        disabled={!role.assignable}
                                    />
                                    <Label
                                        htmlFor={`user-role-${role.name}`}
                                        className={
                                            role.assignable
                                                ? undefined
                                                : 'opacity-60'
                                        }
                                    >
                                        {role.name}
                                        {!role.assignable &&
                                            (role.isAdmin
                                                ? ' (admins only)'
                                                : ' (grants more than you hold)')}
                                    </Label>
                                </div>
                            ))}
                            <InputError message={errors.roles} />
                        </fieldset>
                    )}
                </div>
            )}
        </FormDialog>
    );
}
