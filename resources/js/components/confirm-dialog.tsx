import { useRef, useState } from 'react';
import type { ReactNode } from 'react';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';

type ConfirmDialogProps = {
    /**
     * The control that opens the dialog. Omit it when driving `open` yourself —
     * which you must do when the trigger lives in a `RowActions` menu, because
     * the menu unmounts its own subtree on select and would take the dialog
     * with it.
     */
    trigger?: ReactNode;
    open?: boolean;
    onOpenChange?: (open: boolean) => void;
    title: string;
    description?: ReactNode;
    /** Extra content between the description and the buttons. */
    children?: ReactNode;
    confirmLabel?: string;
    /** Replaces the confirm label while the promise is settling. */
    pendingLabel?: string;
    cancelLabel?: string;
    /** Red confirm button, and focus starts on Cancel rather than Confirm. */
    destructive?: boolean;
    /**
     * Require the user to type this exact string before confirming. Reserve it
     * for the genuinely unrecoverable — asking for it routinely trains people
     * to type without reading.
     */
    confirmationText?: string;
    /**
     * Returning a promise keeps the dialog open and disabled until it settles;
     * it closes on resolve and stays open on reject, so a failed request leaves
     * the user where they can read the error and try again.
     */
    onConfirm: () => void | Promise<unknown>;
};

export function ConfirmDialog({
    trigger,
    open,
    onOpenChange,
    title,
    description,
    children,
    confirmLabel = 'Confirm',
    pendingLabel,
    cancelLabel = 'Cancel',
    destructive = false,
    confirmationText,
    onConfirm,
}: ConfirmDialogProps) {
    const [uncontrolledOpen, setUncontrolledOpen] = useState(false);
    const [pending, setPending] = useState(false);
    const [typed, setTyped] = useState('');
    const cancelRef = useRef<HTMLButtonElement>(null);

    const isControlled = open !== undefined;
    const isOpen = isControlled ? open : uncontrolledOpen;

    // Reopening must not inherit the last attempt's half-typed confirmation.
    // Adjusting during render rather than in an effect catches the case a
    // close handler cannot see: a parent flipping a controlled `open` to false
    // on its own. React discards this pass and re-renders before painting.
    const [wasOpen, setWasOpen] = useState(isOpen);

    if (wasOpen !== isOpen) {
        setWasOpen(isOpen);

        if (!isOpen) {
            setTyped('');
        }
    }

    function setOpen(next: boolean) {
        // Radix asks to close on Escape, outside clicks and the X. While a
        // request is in flight none of those should strand it half-done.
        if (pending) {
            return;
        }

        if (!isControlled) {
            setUncontrolledOpen(next);
        }

        onOpenChange?.(next);
    }

    async function handleConfirm() {
        const result = onConfirm();

        if (!(result instanceof Promise)) {
            if (!isControlled) {
                setUncontrolledOpen(false);
            }

            onOpenChange?.(false);

            return;
        }

        setPending(true);

        try {
            await result;

            if (!isControlled) {
                setUncontrolledOpen(false);
            }

            onOpenChange?.(false);
        } catch {
            // Deliberately swallowed: the caller owns error reporting, and the
            // dialog's job is only to stay open so there is something to
            // report against.
        } finally {
            setPending(false);
        }
    }

    const confirmed =
        confirmationText === undefined || typed.trim() === confirmationText;

    return (
        <Dialog open={isOpen} onOpenChange={setOpen}>
            {trigger && <DialogTrigger asChild>{trigger}</DialogTrigger>}
            <DialogContent
                showCloseButton={!pending}
                onOpenAutoFocus={(event) => {
                    // Landing on the destructive button means a stray Enter
                    // confirms. Start on Cancel and make them travel.
                    if (destructive) {
                        event.preventDefault();
                        cancelRef.current?.focus();
                    }
                }}
                onEscapeKeyDown={(event) => pending && event.preventDefault()}
                onInteractOutside={(event) => pending && event.preventDefault()}
            >
                <DialogHeader>
                    <DialogTitle>{title}</DialogTitle>
                    {description && (
                        <DialogDescription>{description}</DialogDescription>
                    )}
                </DialogHeader>

                {children}

                {confirmationText !== undefined && (
                    <div className="grid gap-2">
                        <Label htmlFor="confirm-text">
                            Type{' '}
                            <span className="font-mono font-medium">
                                {confirmationText}
                            </span>{' '}
                            to continue
                        </Label>
                        <Input
                            id="confirm-text"
                            value={typed}
                            autoComplete="off"
                            disabled={pending}
                            onChange={(event) => setTyped(event.target.value)}
                        />
                    </div>
                )}

                <DialogFooter>
                    <Button
                        ref={cancelRef}
                        variant="outline"
                        disabled={pending}
                        onClick={() => setOpen(false)}
                    >
                        {cancelLabel}
                    </Button>
                    <Button
                        variant={destructive ? 'destructive' : 'default'}
                        disabled={pending || !confirmed}
                        onClick={handleConfirm}
                    >
                        {pending && <Spinner />}
                        {pending
                            ? (pendingLabel ?? confirmLabel)
                            : confirmLabel}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
