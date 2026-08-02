import { KeyRound, Trash2 } from 'lucide-react';
import { ConfirmDialog } from '@/components/confirm-dialog';
import { Button } from '@/components/ui/button';
import type { Passkey } from '@/types/auth';

type Props = {
    passkey: Passkey;
    /** Resolves when the passkey is gone; rejects if the request failed. */
    onDelete: (id: number) => Promise<void>;
};

export default function PasskeyItem({ passkey, onDelete }: Props) {
    return (
        <div className="flex items-center justify-between border-b p-4 last:border-b-0">
            <div className="flex items-center gap-4">
                <div className="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-muted">
                    <KeyRound className="h-5 w-5 text-muted-foreground" />
                </div>
                <div className="space-y-1">
                    <div className="flex items-center gap-2.5">
                        <p className="font-medium tracking-tight">
                            {passkey.name}
                        </p>
                        {passkey.authenticator && (
                            <span className="inline-flex items-center gap-1 rounded-md bg-muted px-2 py-0.5 text-[11px] font-medium tracking-wide text-muted-foreground uppercase ring-1 ring-border ring-inset">
                                {passkey.authenticator}
                            </span>
                        )}
                    </div>
                    <p className="text-sm text-muted-foreground">
                        Added {passkey.created_at_diff}
                        {passkey.last_used_at_diff && (
                            <>
                                <span className="mx-1 text-muted-foreground/50">
                                    /
                                </span>
                                Last used {passkey.last_used_at_diff}
                            </>
                        )}
                    </p>
                </div>
            </div>

            <ConfirmDialog
                destructive
                trigger={
                    <Button
                        variant="ghost"
                        size="sm"
                        className="text-destructive hover:bg-destructive/10 hover:text-destructive"
                    >
                        <Trash2 className="h-4 w-4" />
                        <span className="sr-only">
                            Remove the {passkey.name} passkey
                        </span>
                    </Button>
                }
                title="Remove passkey"
                description={`Are you sure you want to remove the "${passkey.name}" passkey? You will no longer be able to use it to sign in.`}
                confirmLabel="Remove passkey"
                pendingLabel="Removing"
                onConfirm={() => onDelete(passkey.id)}
            />
        </div>
    );
}
