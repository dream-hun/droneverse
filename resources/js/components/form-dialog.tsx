import { Form } from '@inertiajs/react';
import { useState } from 'react';
import type { ComponentProps, ReactNode } from 'react';
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
import { Spinner } from '@/components/ui/spinner';

/**
 * Instantiated with `TForm` rather than left to its default, so a caller's
 * field types survive the wrapper — without this, `errors.password` in the
 * render prop degrades to an index into `object` and stops type-checking.
 */
type InertiaFormProps<TForm extends object> = ComponentProps<
    typeof Form<TForm>
>;

/**
 * Every prop Inertia's `<Form>` takes, plus the dialog around it. Deriving the
 * type rather than restating it means `transform`, `errorBag`, `headers` and
 * anything Inertia adds later work here without this file changing.
 */
type FormDialogProps<TForm extends object> = Omit<
    InertiaFormProps<TForm>,
    'children' | 'className'
> & {
    /** Omit when driving `open` yourself, e.g. from a `RowActions` menu. */
    trigger?: ReactNode;
    open?: boolean;
    onOpenChange?: (open: boolean) => void;
    title: string;
    description?: ReactNode;
    submitLabel?: string;
    pendingLabel?: string;
    cancelLabel?: string;
    destructive?: boolean;
    /**
     * Classes for the dialog panel. A long form — a mission, with its code
     * and its world — wants a wider panel that scrolls rather than the
     * default one that grows off the screen.
     */
    contentClassName?: string;
    /**
     * Forwarded to the submit button. Mostly an escape hatch for end-to-end
     * test hooks: `submitProps={{ 'data-test': 'confirm-delete' }}`.
     */
    submitProps?: ComponentProps<'button'> &
        Partial<Record<`data-${string}`, string>>;
    /**
     * The fields. Use the render-prop form to reach `errors` and `processing`;
     * the footer buttons are supplied for you.
     */
    children: InertiaFormProps<TForm>['children'];
};

/**
 * A create/edit/delete form in a modal, submitted through Inertia.
 *
 * Closes itself on success and stays open on validation failure, which is the
 * whole reason this exists: hand-rolled versions close on submit and drop the
 * user back on the page with errors they cannot see.
 *
 * It raises no toast of its own. Success messaging is server-driven here —
 * `useFlashToast` renders whatever the controller flashed — and a client-side
 * toast on top of that shows the user two of everything.
 */
export function FormDialog<TForm extends object = Record<string, any>>({
    trigger,
    open,
    onOpenChange,
    title,
    description,
    submitLabel = 'Save',
    pendingLabel,
    cancelLabel = 'Cancel',
    destructive = false,
    contentClassName,
    submitProps,
    children,
    onSuccess,
    options,
    ...formProps
}: FormDialogProps<TForm>) {
    const [uncontrolledOpen, setUncontrolledOpen] = useState(false);

    const isControlled = open !== undefined;
    const isOpen = isControlled ? open : uncontrolledOpen;

    function setOpen(next: boolean) {
        if (!isControlled) {
            setUncontrolledOpen(next);
        }

        onOpenChange?.(next);
    }

    return (
        <Dialog open={isOpen} onOpenChange={setOpen}>
            {trigger && <DialogTrigger asChild>{trigger}</DialogTrigger>}
            <DialogContent className={contentClassName}>
                <DialogHeader>
                    <DialogTitle>{title}</DialogTitle>
                    {description && (
                        <DialogDescription>{description}</DialogDescription>
                    )}
                </DialogHeader>

                <Form
                    {...formProps}
                    // Preserving scroll keeps a validation failure from
                    // jumping the page behind the dialog to the top.
                    options={{ preserveScroll: true, ...options }}
                    onSuccess={(page) => {
                        setOpen(false);
                        onSuccess?.(page);
                    }}
                    className="space-y-6"
                >
                    {(renderProps) => (
                        <>
                            {typeof children === 'function'
                                ? children(renderProps)
                                : children}

                            <DialogFooter>
                                <Button
                                    type="button"
                                    variant="outline"
                                    disabled={renderProps.processing}
                                    onClick={() => {
                                        renderProps.resetAndClearErrors();
                                        setOpen(false);
                                    }}
                                >
                                    {cancelLabel}
                                </Button>
                                <Button
                                    type="submit"
                                    variant={
                                        destructive ? 'destructive' : 'default'
                                    }
                                    disabled={renderProps.processing}
                                    {...submitProps}
                                >
                                    {renderProps.processing && <Spinner />}
                                    {renderProps.processing
                                        ? (pendingLabel ?? submitLabel)
                                        : submitLabel}
                                </Button>
                            </DialogFooter>
                        </>
                    )}
                </Form>
            </DialogContent>
        </Dialog>
    );
}
