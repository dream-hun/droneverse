import { Form, Head, Link } from '@inertiajs/react';
import { ExternalLink, Receipt } from 'lucide-react';
import { useState } from 'react';
import SubscriptionController from '@/actions/App/Http/Controllers/Settings/SubscriptionController';
import { DataTable } from '@/components/data-table';
import { FormDialog } from '@/components/form-dialog';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
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
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import type { ColumnDef } from '@/lib/data-table';
import { pricing } from '@/routes';
import { edit as editBilling } from '@/routes/billing';
import { edit as editPaymentMethod } from '@/routes/payment-method';
import type { PlanValue } from '@/types/auth';
import type {
    BillingOrder,
    BillingPlan,
    BillingSubscription,
    PlanVariant,
    SwitchablePlan,
} from '@/types/billing';

type BillingProps = {
    plan: BillingPlan;
    subscription: BillingSubscription | null;
    switchable: SwitchablePlan[];
    orders: BillingOrder[];
};

const VARIANT_LABELS: Record<PlanVariant, string> = {
    monthly: 'Monthly',
    yearly: 'Yearly',
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
 * `total` carries no `sortValue` on purpose: it arrives pre-formatted
 * ("$12.00"), and sorting that as text puts $9 after $10. Sorting it properly
 * needs a raw minor-unit amount on the payload, which is a backend change.
 *
 * `orderNumber` has no such problem — Lemon Squeezy counts orders with an
 * integer, and it is sent as one — so that column sorts on the number itself.
 */
const ORDER_COLUMNS: ColumnDef<BillingOrder>[] = [
    {
        id: 'orderedAt',
        header: 'Date',
        className: 'whitespace-nowrap',
        sortValue: (order) =>
            order.orderedAt ? new Date(order.orderedAt) : null,
        cell: (order) => formatDate(order.orderedAt) ?? '—',
    },
    {
        id: 'orderNumber',
        header: 'Order',
        className: 'whitespace-nowrap tabular-nums',
        sortValue: (order) => order.orderNumber,
        cell: (order) => `#${order.orderNumber}`,
    },
    {
        id: 'status',
        header: 'Status',
        className: 'capitalize',
        sortValue: (order) => order.status,
        cell: (order) => (
            <span className="flex items-center gap-2">
                <span>{order.status.replace('_', ' ')}</span>
                {order.refunded && <Badge variant="secondary">Refunded</Badge>}
            </span>
        ),
    },
    {
        id: 'total',
        header: 'Total',
        align: 'end',
        className: 'whitespace-nowrap',
        cell: (order) => order.total,
    },
    {
        /*
         * Lemon Squeezy hosts the receipt itself and there is no PDF to serve
         * from here, so this is a link out rather than a download. An order
         * that has none — one that never reached `paid` — shows nothing at all
         * rather than a link that would 404 on arrival.
         */
        id: 'receipt',
        header: '',
        srHeader: 'Receipt',
        align: 'end',
        width: 'w-16',
        cell: (order) =>
            order.receiptUrl ? (
                <Button asChild variant="ghost" size="sm">
                    <a
                        href={order.receiptUrl}
                        target="_blank"
                        rel="noreferrer noopener"
                    >
                        Receipt
                        <ExternalLink aria-hidden />
                    </a>
                </Button>
            ) : null,
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
    const renewsAt = formatDate(subscription?.renewsAt ?? null);

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

                /*
                 * The date and nothing more. Lemon Squeezy mirrors `renews_at`
                 * onto the subscription but publishes no forthcoming amount,
                 * and quoting the last order's total as the next one would be
                 * wrong the first time a price or a seat count changed.
                 */
                return renewsAt ? `Renews ${renewsAt}.` : 'Active.';
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

/**
 * Move the subscription onto another plan or billing period.
 *
 * Both directions and both periods through one control, because to the pilot
 * they are one decision: an upgrade, a downgrade and a move to annual billing
 * differ only in what Lemon Squeezy prorates afterwards.
 *
 * The selects are controlled but the values they submit are the plain `plan`
 * and `variant` fields the server validates — Radix renders a hidden input per
 * `name`, so Inertia's `<Form>` serializes them like any other field and this
 * component never assembles a request of its own.
 */
function ChangePlanDialog({ plans }: { plans: SwitchablePlan[] }) {
    const current = plans.find((plan) =>
        plan.variants.some((variant) => variant.isCurrent),
    );

    const [planValue, setPlanValue] = useState(
        current?.value ?? plans[0]?.value,
    );
    const [variantValue, setVariantValue] = useState<PlanVariant | undefined>(
        current?.variants.find((variant) => variant.isCurrent)?.value,
    );

    /*
     * Derived rather than stored, so switching to a plan that does not sell the
     * period currently picked cannot leave the form holding one that is not on
     * offer. There is always something selected: a plan reaches this list only
     * with at least one switchable period on it.
     */
    const plan = plans.find((option) => option.value === planValue) ?? plans[0];
    const variant =
        plan.variants.find((option) => option.value === variantValue) ??
        plan.variants[0];

    return (
        <FormDialog
            {...SubscriptionController.swap.form()}
            trigger={
                <Button
                    variant="outline"
                    size="sm"
                    data-test="change-plan-button"
                >
                    Change plan
                </Button>
            }
            title="Change your plan"
            description="Nothing is charged today. Lemon Squeezy works out what the rest of your current period is worth and settles the difference on your next renewal."
            submitLabel="Change plan"
            pendingLabel="Changing…"
            submitProps={{
                'data-test': 'confirm-change-plan-button',
                // The one selection that cannot go anywhere. Submitting it is
                // harmless — the server answers "you are already on that plan"
                // — but a button that does nothing is worse than one that is
                // visibly unavailable.
                disabled: variant.isCurrent,
            }}
        >
            {({ errors }) => (
                <div className="grid gap-4">
                    <div className="grid gap-2">
                        <Label htmlFor="plan">Plan</Label>
                        <Select
                            name="plan"
                            value={plan.value}
                            onValueChange={(value) =>
                                setPlanValue(value as PlanValue)
                            }
                        >
                            <SelectTrigger id="plan" className="w-full">
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                {plans.map((option) => (
                                    <SelectItem
                                        key={option.value}
                                        value={option.value}
                                    >
                                        {option.label}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                        <p className="text-sm text-muted-foreground">
                            {plan.tagline}
                        </p>
                        <InputError message={errors.plan} />
                    </div>

                    <div className="grid gap-2">
                        <Label htmlFor="variant">Billing period</Label>
                        <Select
                            name="variant"
                            value={variant.value}
                            onValueChange={(value) =>
                                setVariantValue(value as PlanVariant)
                            }
                        >
                            <SelectTrigger id="variant" className="w-full">
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                {plan.variants.map((option) => (
                                    <SelectItem
                                        key={option.value}
                                        value={option.value}
                                    >
                                        {VARIANT_LABELS[option.value]} —{' '}
                                        {option.formatted}
                                        {option.value === 'yearly'
                                            ? '/year'
                                            : '/month'}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                        <InputError message={errors.variant} />
                    </div>

                    {variant.isCurrent && (
                        <p className="text-sm text-muted-foreground">
                            This is the plan you are on already.
                        </p>
                    )}
                </div>
            )}
        </FormDialog>
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
    switchable,
    orders,
}: BillingProps) {
    const canCancel = Boolean(
        subscription && subscription.valid && !subscription.cancelled,
    );
    const canResume = Boolean(subscription?.onGracePeriod);
    const hasSubscription = plan.source === 'subscription';

    /*
     * Lemon Squeezy keeps the card's brand and last four on the subscription
     * row, so the page can name the card being charged without a network call.
     * Both are absent until the first webhook lands, hence the pair check.
     */
    const card =
        subscription?.cardBrand && subscription.cardLastFour
            ? `${subscription.cardBrand} ending ${subscription.cardLastFour}`
            : null;

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
                    <div className="flex flex-wrap items-center gap-2">
                        <Button asChild variant="outline" size="sm">
                            <Link href={editPaymentMethod()}>
                                Update payment method
                                <ExternalLink aria-hidden />
                            </Link>
                        </Button>

                        {card && (
                            <span className="text-sm text-muted-foreground capitalize">
                                {card}
                            </span>
                        )}

                        {/*
                         * Absent while there is nothing to move — a cancelled
                         * subscription is resumed before it is repriced, and the
                         * server says so by sending an empty list.
                         */}
                        {switchable.length > 0 && (
                            <ChangePlanDialog plans={switchable} />
                        )}

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
                    columns={ORDER_COLUMNS}
                    rows={orders}
                    rowKey={(order) => order.id}
                    caption="Your payment history"
                    defaultSort={{ columnId: 'orderedAt', direction: 'desc' }}
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
