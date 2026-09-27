import { Head, Link, router } from '@inertiajs/react';
import { ExternalLink } from 'lucide-react';
import { useState } from 'react';
import CheckoutController from '@/actions/App/Http/Controllers/CheckoutController';
import {
    BillingPeriodToggle,
    periodSuffix,
} from '@/components/billing-period-toggle';
import Heading from '@/components/heading';
import { Button } from '@/components/ui/button';
import { pricing } from '@/routes';
import { edit as editBilling } from '@/routes/billing';
import { edit as editBillingPortal } from '@/routes/billing-portal';
import type { BillingPlan, BillingUpgrade, PlanVariant } from '@/types/billing';

type BillingProps = {
    plan: BillingPlan;
    /** The plan a pilot on Starter can move up to; null for everyone else. */
    upgrade: BillingUpgrade | null;
    /** Whether Kelviq bills this pilot, and so has a portal to open. */
    canManageBilling: boolean;
};

/**
 * The one line at the top that says where the pilot stands.
 *
 * Phrased from `source` rather than from a subscription: a comped account holds
 * a plan with no subscription behind it, and telling those pilots "no active
 * subscription" would be alarming and wrong.
 */
function PlanSummary({ plan }: { plan: BillingPlan }) {
    const detail = {
        override: 'Granted directly on your account. There is nothing to bill.',
        kelviq: 'Paid through Kelviq. Your invoices, card and subscription all live in the billing portal.',
        none: 'The free tier: three beginner courses and five missions, for as long as you like.',
    }[plan.source];

    return (
        <div className="rounded-lg border border-border p-4">
            <p className="font-medium">{plan.label}</p>
            <p className="mt-1 text-sm text-muted-foreground">{detail}</p>
        </div>
    );
}

/**
 * The upgrade to Pro, priced under a monthly / yearly / lifetime toggle.
 *
 * Posts a tier and a period and nothing else, and follows the server to
 * Kelviq's hosted checkout. A refusal comes back as a flashed toast.
 */
function UpgradeCard({ upgrade }: { upgrade: BillingUpgrade }) {
    const [variant, setVariant] = useState<PlanVariant>('monthly');
    const [processing, setProcessing] = useState(false);

    const price = upgrade.prices[variant];
    const saving = upgrade.prices.yearly?.savingPercent ?? null;
    const purchasable = price?.purchasable === true;

    const startCheckout = () => {
        router.post(
            CheckoutController.store.url(),
            { plan: upgrade.value, variant },
            {
                preserveScroll: true,
                onStart: () => setProcessing(true),
                onFinish: () => setProcessing(false),
            },
        );
    };

    return (
        <div className="space-y-4 rounded-lg border border-primary/40 p-4">
            <div>
                <p className="font-medium">Upgrade to {upgrade.label}</p>
                <p className="mt-1 text-sm text-muted-foreground">
                    {upgrade.tagline}
                </p>
            </div>

            <ul className="space-y-1 text-sm text-muted-foreground">
                {upgrade.highlights.map((highlight) => (
                    <li key={highlight} className="flex gap-2">
                        <span aria-hidden className="text-primary">
                            ✓
                        </span>
                        <span>{highlight}</span>
                    </li>
                ))}
            </ul>

            <BillingPeriodToggle
                variant={variant}
                onChange={setVariant}
                saving={saving}
            />

            <div className="flex flex-wrap items-center gap-4">
                {price && (
                    <p className="text-2xl font-bold tracking-tight">
                        {price.formatted}
                        <span className="text-sm font-normal text-muted-foreground">
                            {periodSuffix(variant)}
                        </span>
                    </p>
                )}

                <Button
                    onClick={startCheckout}
                    disabled={!purchasable || processing}
                    data-test="upgrade-button"
                >
                    {purchasable
                        ? `Upgrade to ${upgrade.label}`
                        : 'Not yet available'}
                </Button>

                <Link
                    href={pricing()}
                    className="text-sm text-muted-foreground underline-offset-4 hover:underline"
                >
                    Compare plans
                </Link>
            </div>
        </div>
    );
}

export default function Billing({
    plan,
    upgrade,
    canManageBilling,
}: BillingProps) {
    return (
        <>
            <Head title="Billing settings" />

            <h1 className="sr-only">Billing settings</h1>

            <div className="space-y-6">
                <Heading
                    variant="small"
                    title="Billing"
                    description="Your plan, and where to change it"
                />

                <PlanSummary plan={plan} />

                {upgrade && <UpgradeCard upgrade={upgrade} />}

                {canManageBilling && (
                    <div className="flex flex-wrap items-center gap-2">
                        {/*
                         * One button for the card, the invoices, the billing
                         * period and cancelling. A link out rather than a
                         * screen we render, because card details should never
                         * touch a page of ours.
                         */}
                        <Button asChild variant="outline" size="sm">
                            <Link
                                href={editBillingPortal()}
                                data-test="manage-billing-button"
                            >
                                Manage billing
                                <ExternalLink aria-hidden />
                            </Link>
                        </Button>
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
