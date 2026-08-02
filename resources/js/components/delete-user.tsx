import { useRef } from 'react';
import ProfileController from '@/actions/App/Http/Controllers/Settings/ProfileController';
import { FormDialog } from '@/components/form-dialog';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import PasswordInput from '@/components/password-input';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';

export default function DeleteUser() {
    const passwordInput = useRef<HTMLInputElement>(null);

    return (
        <div className="space-y-6">
            <Heading
                variant="small"
                title="Delete account"
                description="Delete your account and all of its resources"
            />
            <div className="space-y-4 rounded-lg border border-red-100 bg-red-50 p-4 dark:border-red-200/10 dark:bg-red-700/10">
                <div className="relative space-y-0.5 text-red-600 dark:text-red-100">
                    <p className="font-medium">Warning</p>
                    <p className="text-sm">
                        Please proceed with caution, this cannot be undone.
                    </p>
                </div>

                <FormDialog
                    {...ProfileController.destroy.form()}
                    destructive
                    resetOnSuccess
                    onError={() => passwordInput.current?.focus()}
                    trigger={
                        <Button
                            variant="destructive"
                            data-test="delete-user-button"
                        >
                            Delete account
                        </Button>
                    }
                    title="Are you sure you want to delete your account?"
                    description="Once your account is deleted, all of its resources and data will also be permanently deleted. Please enter your password to confirm you would like to permanently delete your account."
                    submitLabel="Delete account"
                    pendingLabel="Deleting account"
                    submitProps={{
                        'data-test': 'confirm-delete-user-button',
                    }}
                >
                    {({ errors }) => (
                        <div className="grid gap-2">
                            <Label htmlFor="password" className="sr-only">
                                Password
                            </Label>

                            <PasswordInput
                                id="password"
                                name="password"
                                ref={passwordInput}
                                placeholder="Password"
                                autoComplete="current-password"
                            />

                            <InputError message={errors.password} />
                        </div>
                    )}
                </FormDialog>
            </div>
        </div>
    );
}
