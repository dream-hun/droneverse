import { Form, Head, Link } from '@inertiajs/react';
import { ExternalLink } from 'lucide-react';
import SubscriptionController from '@/actions/App/Http/Controllers/Settings/SubscriptionController';
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

                {transactions.length === 0 ? (
                    <p className="text-sm text-muted-foreground">
                        Nothing yet. Receipts appear here the moment a payment
                        clears.
                    </p>
                ) : (
                    <div className="overflow-x-auto rounded-lg border border-border">
                        <table className="w-full border-collapse text-sm">
                            <caption className="sr-only">
                                Your payment history
                            </caption>
                            <thead>
                                <tr className="border-b border-border">
                                    <th
                                        scope="col"
                                        className="p-3 text-left font-medium"
                                    >
                                        Date
                                    </th>
                                    <th
                                        scope="col"
                                        className="p-3 text-left font-medium"
                                    >
                                        Invoice
                                    </th>
                                    <th
                                        scope="col"
                                        className="p-3 text-left font-medium"
                                    >
                                        Status
                                    </th>
                                    <th
                                        scope="col"
                                        className="p-3 text-right font-medium"
                                    >
                                        Total
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                {transactions.map((transaction) => (
                                    <tr
                                        key={transaction.id}
                                        className="border-b border-border last:border-0"
                                    >
                                        <td className="p-3 whitespace-nowrap">
                                            {formatDate(transaction.billedAt) ??
                                                '—'}
                                        </td>
                                        <td className="p-3 whitespace-nowrap">
                                            {transaction.invoiceNumber ?? '—'}
                                        </td>
                                        <td className="p-3 capitalize">
                                            {transaction.status.replace(
                                                '_',
                                                ' ',
                                            )}
                                        </td>
                                        <td className="p-3 text-right whitespace-nowrap">
                                            {transaction.total}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}
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
