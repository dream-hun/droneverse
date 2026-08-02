import { Form, Head, Link } from '@inertiajs/react';
import { ExternalLink, Receipt } from 'lucide-react';
import SubscriptionController from '@/actions/App/Http/Controllers/Settings/SubscriptionController';
import { DataTable } from '@/components/data-table';
import Heading from '@/components/heading';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import {
    EmptyState,
    EmptyStateDescription,
    EmptyStateIcon,
    EmptyStateTitle,
} from '@/components/ui/empty-state';
import type { ColumnDef } from '@/lib/data-table';
import { pricing } from '@/routes';
import { edit as editBilling } from '@/routes/billing';
import { edit as editPaymentMethod } from '@/routes/payment-method';
import type {
    BillingPlan,
    BillingSubscription,
    BillingTransaction,
} from '@/types/billing';

type BillingProps = {
    plan: BillingPlan;
    subscription: BillingSubscription | null;
    transactions: BillingTransaction[];
};

const DATE_FORMAT: Intl.DateTimeFormatOptions = {
    day: 'numeric',
    month: 'short',
    year: 'numeric',
};

function formatDate(value: string | null): string | null {
    return value
        ? new Date(value).toLocaleDateString(undefined, DATE_FORMAT)
        : null;
}

/**
 * `total` carries no `sortValue` on purpose: Paddle sends it pre-formatted
 * ("$12.00"), and sorting that as text puts $9 after $10. Sorting it properly
 * needs a raw minor-unit amount on the payload, which is a backend change.
 */
const TRANSACTION_COLUMNS: ColumnDef<BillingTransaction>[] = [
    {
        id: 'billedAt',
        header: 'Date',
        className: 'whitespace-nowrap',
        sortValue: (transaction) =>
            transaction.billedAt ? new Date(transaction.billedAt) : null,
        cell: (transaction) => formatDate(transaction.billedAt) ?? '—',
    },
    {
        id: 'invoiceNumber',
        header: 'Invoice',
        className: 'whitespace-nowrap',
        sortValue: (transaction) => transaction.invoiceNumber,
        cell: (transaction) => transaction.invoiceNumber ?? '—',
    },
    {
        id: 'status',
        header: 'Status',
        className: 'capitalize',
        sortValue: (transaction) => transaction.status,
        cell: (transaction) => transaction.status.replace('_', ' '),
    },
    {
        id: 'total',
        header: 'Total',
        align: 'end',
        className: 'whitespace-nowrap',
        cell: (transaction) => transaction.total,
    },
];

/**
 * The one line at the top that says where the pilot stands.
 *
 * Deliberately phrased from `source` rather than from the subscription: a
 * comped account holds a plan with no subscription behind it, and telling those
 * pilots "no active subscription" would be alarming and wrong.
 */
function PlanSummary({
    plan,
    subscription,
}: {
    plan: BillingPlan;
    subscription: BillingSubscription | null;
}) {
    const endsAt = formatDate(subscription?.endsAt ?? null);
    const renewsAt = formatDate(subscription?.nextPayment?.date ?? null);

    const detail = (() => {
        switch (plan.source) {
            case 'override':
                return 'Granted directly on your account. There is nothing to bill.';
            case 'subscription':
                if (subscription?.onGracePeriod) {
                    return endsAt
                        ? `Cancelled. You keep everything until ${endsAt}.`
                        : 'Cancelled. You keep everything until the end of the period you have paid for.';
                }

                if (subscription?.pastDue) {
                    return 'Your last payment did not go through. Update your payment method to keep flying.';
                }

                if (subscription?.paused) {
                    return 'Paused.';
                }

                if (subscription?.onTrial) {
                    const trialEnds = formatDate(subscription.trialEndsAt);

                    return trialEnds
                        ? `On trial until ${trialEnds}.`
                        : 'On trial.';
                }

                return renewsAt && subscription?.nextPayment
                    ? `Renews ${renewsAt} for ${subscription.nextPayment.amount}.`
                    : 'Active.';
            default:
                return 'The free tier: three beginner courses and five missions, for as long as you like.';
        }
    })();

    return (
        <div className="rounded-lg border border-border p-4">
            <div className="flex flex-wrap items-center justify-between gap-3">
                <div className="flex items-center gap-2">
                    <p className="font-medium">{plan.label}</p>
                    {subscription?.onGracePeriod && (
                        <Badge variant="secondary">Ending</Badge>
                    )}
                    {subscription?.pastDue && (
                        <Badge variant="destructive">Past due</Badge>
                    )}
                </div>

                {!plan.isPaid && (
                    <Button asChild size="sm">
                        <Link href={pricing()}>See plans</Link>
                    </Button>
                )}
            </div>

            <p className="mt-1 text-sm text-muted-foreground">{detail}</p>
        </div>
    );
}

function CancelSubscriptionDialog() {
    return (
        <Dialog>
            <DialogTrigger asChild>
                <Button
                    variant="outline"
                    size="sm"
                    data-test="cancel-subscription-button"
                >
                    Cancel subscription
                </Button>
            </DialogTrigger>
            <DialogContent>
                <DialogTitle>Cancel your subscription?</DialogTitle>
                <DialogDescription>
                    Nothing changes today. You keep every course, mission and
                    tool until the end of the period you have already paid for,
                    and you can call this off any time before then.
                </DialogDescription>

                <Form
                    {...SubscriptionController.destroy.form()}
                    options={{ preserveScroll: true }}
                >
                    {({ processing }) => (
                        <DialogFooter className="gap-2">
                            <DialogClose asChild>
                                <Button variant="secondary">Keep flying</Button>
                            </DialogClose>

                            <Button
                                variant="destructive"
                                disabled={processing}
                                asChild
                            >
                                <button
                                    type="submit"
                                    data-test="confirm-cancel-subscription-button"
                                >
                                    Cancel subscription
                                </button>
                            </Button>
                        </DialogFooter>
                    )}
                </Form>
            </DialogContent>
        </Dialog>
    );
}

export default function Billing({
    plan,
    subscription,
    transactions,
}: BillingProps) {
    const canCancel = Boolean(
        subscription && subscription.valid && !subscription.canceled,
    );
    const canResume = Boolean(subscription?.onGracePeriod);
    const hasSubscription = plan.source === 'subscription';

    return (
        <>
            <Head title="Billing settings" />

            <h1 className="sr-only">Billing settings</h1>

            <div className="space-y-6">
                <Heading
                    variant="small"
                    title="Billing"
                    description="Your plan, your next bill and your receipts"
                />

                <PlanSummary plan={plan} subscription={subscription} />

                {hasSubscription && (
                    <div className="flex flex-wrap gap-2">
                        <Button asChild variant="outline" size="sm">
                            <Link href={editPaymentMethod()}>
                                Update payment method
                                <ExternalLink aria-hidden />
                            </Link>
                        </Button>

                        {canResume && (
                            <Form
                                {...SubscriptionController.update.form()}
                                options={{ preserveScroll: true }}
                            >
                                {({ processing }) => (
                                    <Button
                                        size="sm"
                                        disabled={processing}
                                        asChild
                                    >
                                        <button
                                            type="submit"
                                            data-test="resume-subscription-button"
                                        >
                                            Resume subscription
                                        </button>
                                    </Button>
                                )}
                            </Form>
                        )}

                        {canCancel && <CancelSubscriptionDialog />}
                    </div>
                )}
            </div>

            <div className="space-y-6">
                <Heading
                    variant="small"
                    title="Receipts"
                    description="Every payment DroneVerse has taken from this account"
                />

                <DataTable
                    columns={TRANSACTION_COLUMNS}
                    rows={transactions}
                    rowKey={(transaction) => transaction.id}
                    caption="Your payment history"
                    defaultSort={{ columnId: 'billedAt', direction: 'desc' }}
                    empty={
                        <EmptyState className="rounded-none border-0">
                            <EmptyStateIcon>
                                <Receipt />
                            </EmptyStateIcon>
                            <EmptyStateTitle>No receipts yet</EmptyStateTitle>
                            <EmptyStateDescription>
                                Receipts appear here the moment a payment
                                clears.
                            </EmptyStateDescription>
                        </EmptyState>
                    }
                />
            </div>
        </>
    );
}

Billing.layout = {
    breadcrumbs: [
        {
            title: 'Billing settings',
            href: editBilling(),
        },
    ],
};
