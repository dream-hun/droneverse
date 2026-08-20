import { Head, Link, router, useHttp, usePage } from '@inertiajs/react';
import { Check, Minus } from 'lucide-react';
import { useCallback, useEffect, useId, useRef, useState } from 'react';
import { toast } from 'sonner';
import CheckoutController from '@/actions/App/Http/Controllers/CheckoutController';
import SubscriptionController from '@/actions/App/Http/Controllers/Settings/SubscriptionController';
import { ConfirmDialog } from '@/components/confirm-dialog';
import { SectionLabel } from '@/components/marketing/marketing-shell';
import { SiteFooter } from '@/components/marketing/site-footer';
import { SiteHeader } from '@/components/marketing/site-header';
import {
    Table,
    TableBody,
    TableCaption,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { useLemonSqueezy } from '@/hooks/use-lemon-squeezy';
import { cn } from '@/lib/utils';
import { dashboard, register } from '@/routes';
import { edit as editBilling } from '@/routes/billing';
import { index as coursesIndex } from '@/routes/courses';
import type {
    LemonSqueezyConfig,
    PlanComparisonRow,
    PlanVariant,
    PricingPlan,
} from '@/types/billing';

type PricingProps = {
    plans: PricingPlan[];
    comparison: PlanComparisonRow[];
    salesEmail: string | null;
    lemonSqueezy: LemonSqueezyConfig;
};

/**
 * The server mints a whole checkout URL, so there is nothing here to assemble.
 * That URL is also a working standalone page, which is what lets the hook fall
 * back to a navigation when the overlay script never arrives.
 */
type CheckoutResponse = {
    checkout: { url: string };
};

const FAQS = [
    {
        question: 'Can I change plan later?',
        answer: 'Yes, in either direction and at any time. Upgrading, downgrading and moving to yearly billing all change the subscription you already have rather than starting a second one, and the difference is settled on your next renewal.',
    },
    {
        question: 'Can I cancel my subscription?',
        answer: 'Any time. Cancelling schedules the end of the period you have already paid for; nothing is taken away before then.',
    },
    {
        question: 'Do I keep what I have built if I downgrade?',
        answer: 'Yes. Your progress, photos and scores stay on your account — a downgrade only changes which missions you can fly, never what you have already flown.',
    },
    {
        question: 'Is there a student discount?',
        answer: 'Educational institutions can get in touch about academic pricing. Individual students are welcome on Starter for as long as they like.',
    },
];

/** The square, full-width call to action every plan card ends on. */
function ctaClass(emphasised: boolean) {
    return cn(
        'block w-full py-4 text-center font-bold tracking-widest uppercase transition-all',
        'disabled:cursor-not-allowed disabled:opacity-40',
        emphasised
            ? 'bg-primary text-primary-foreground hover:brightness-110'
            : 'border border-border text-foreground hover:border-primary hover:text-primary',
    );
}

/**
 * The bar above the cards, switching every priced tier between its periods.
 */
function BillingPeriodToggle({
    variant,
    onChange,
    saving,
}: {
    variant: PlanVariant;
    onChange: (variant: PlanVariant) => void;
    saving: number | null;
}) {
    const options: { value: PlanVariant; label: string }[] = [
        { value: 'monthly', label: 'Monthly' },
        { value: 'yearly', label: 'Yearly' },
    ];

    return (
        <div
            role="group"
            aria-label="Billing period"
            className="inline-flex border border-border"
        >
            {options.map((option) => (
                <button
                    key={option.value}
                    type="button"
                    aria-pressed={variant === option.value}
                    onClick={() => onChange(option.value)}
                    className={cn(
                        'px-5 py-2.5 font-mono text-xs tracking-widest uppercase transition-colors',
                        variant === option.value
                            ? 'bg-primary text-primary-foreground'
                            : 'text-muted-foreground hover:text-primary',
                    )}
                >
                    {option.label}
                    {option.value === 'yearly' && saving !== null && (
                        <span
                            className={cn(
                                'ml-2',
                                variant === 'yearly'
                                    ? 'text-primary-foreground/70'
                                    : 'text-primary',
                            )}
                        >
                            save {saving}%
                        </span>
                    )}
                </button>
            ))}
        </div>
    );
}

/**
 * The price line, or the absence of one.
 *
 * Starter and Enterprise carry no amount at all and say so in words — a card
 * showing a blank where a number belongs reads as a page that failed to load.
 */
function PlanPriceLine({
    plan,
    variant,
}: {
    plan: PricingPlan;
    variant: PlanVariant;
}) {
    const price = plan.prices[variant];

    if (!price) {
        return (
            <p className="text-4xl font-extrabold tracking-tighter">
                {plan.value === 'starter' ? 'Free' : 'Custom'}
            </p>
        );
    }

    return (
        <p className="text-4xl font-extrabold tracking-tighter">
            {price.formatted}
            <span className="text-lg font-normal text-muted-foreground">
                {variant === 'yearly' ? ' / year' : ' / month'}
            </span>
        </p>
    );
}

export default function Pricing({
    plans,
    comparison,
    salesEmail,
    lemonSqueezy,
}: PricingProps) {
    const { auth } = usePage().props;
    const [variant, setVariant] = useState<PlanVariant>('monthly');
    const comparisonCaptionId = useId();

    const checkout = useHttp<
        { plan: string; variant: string },
        CheckoutResponse
    >({ plan: '', variant: '' });

    const annualSaving =
        plans.find((plan) => plan.prices.yearly?.savingPercent)?.prices.yearly
            ?.savingPercent ?? null;

    /*
     * The overlay closes the moment Lemon Squeezy has the money, which is not
     * the moment we know about it — the plan is granted by a webhook arriving
     * separately. Read through a ref so the callback below, registered once
     * when lemon.js is set up, can still see the current one.
     */
    const currentPlan = useRef(auth.plan.value);

    useEffect(() => {
        currentPlan.current = auth.plan.value;
    }, [auth.plan.value]);

    const refreshes = useRef<number[]>([]);

    useEffect(
        () => () => refreshes.current.forEach((id) => window.clearTimeout(id)),
        [],
    );

    /**
     * Pull the new plan in once the webhook has landed.
     *
     * Spaced rather than immediate, and it stops as soon as the plan changes.
     * A buyer who reaches this point has paid; the worst case is a page that
     * catches up on their next navigation instead of on its own.
     */
    const onCheckoutCompleted = useCallback(() => {
        const paidFrom = currentPlan.current;

        toast.success('Payment received — activating your plan.');

        refreshes.current = [2000, 5000, 10000].map((delay) =>
            window.setTimeout(() => {
                if (currentPlan.current !== paidFrom) {
                    return;
                }

                router.reload();
            }, delay),
        );
    }, []);

    const { ready, openCheckout } = useLemonSqueezy(lemonSqueezy, {
        onCompleted: onCheckoutCompleted,
    });

    /**
     * Ask the server to open a checkout.
     *
     * The request carries a tier and a period; the price it resolves to is the
     * server's business. The overlay is opened from the URL in the response
     * rather than from anything this page knows.
     *
     * The error branch is the whole of this page's failure reporting. Lemon
     * Squeezy publishes no checkout-failure event of any kind, so once the
     * overlay is open the app is blind to whatever happens inside it. Every
     * failure anybody will hear about is one this request returned: a plan that
     * is not for sale, a variant with no configured ID, or a provider the
     * server could not reach. Swallowing an error here would leave a buyer
     * clicking a button that visibly does nothing.
     */
    const startCheckout = (plan: PricingPlan) => {
        checkout.setData({ plan: plan.value, variant });

        checkout.post(CheckoutController.store.url(), {
            onSuccess: (response) => openCheckout(response.checkout.url),
            onError: (errors) =>
                toast.error(
                    errors.plan ??
                        'Checkout could not be opened. Please try again.',
                ),
        });
    };

    /**
     * Move the subscription this pilot already holds onto another plan.
     *
     * Never a checkout: a second subscription would bill them twice for one
     * account and grant nothing the first one did not. The server decides that
     * this viewer is in a position to switch — the button only appears when it
     * said so — and it answers with a redirect to billing settings, where the
     * new plan and its renewal date are spelled out.
     *
     * The promise is what keeps the dialog open until the request settles, so a
     * rejected change leaves the pilot somewhere they can read the error.
     */
    const switchPlan = (plan: PricingPlan) =>
        new Promise<void>((resolve, reject) => {
            router.put(
                SubscriptionController.swap.url(),
                { plan: plan.value, variant },
                {
                    onSuccess: () => resolve(),
                    onError: (errors) => {
                        toast.error(
                            errors.plan ??
                                errors.variant ??
                                'Your plan could not be changed. Please try again.',
                        );

                        reject(new Error('The plan change was refused.'));
                    },
                },
            );
        });

    const renderCta = (plan: PricingPlan) => {
        const emphasised = plan.isPopular;

        switch (plan.cta.action) {
            case 'signup':
                return (
                    <Link
                        href={auth.user ? dashboard() : register()}
                        className={ctaClass(emphasised)}
                    >
                        {plan.cta.label}
                    </Link>
                );

            case 'contact':
                return salesEmail ? (
                    <a
                        href={`mailto:${salesEmail}?subject=${encodeURIComponent(`${plan.label} enquiry`)}`}
                        className={ctaClass(emphasised)}
                    >
                        {plan.cta.label}
                    </a>
                ) : (
                    <button className={ctaClass(false)} disabled>
                        {plan.cta.label}
                    </button>
                );

            case 'checkout': {
                /*
                 * The server decides that the tier is buyable by this viewer;
                 * whether the period they are looking at is buyable is a
                 * separate answer, and it changes under the toggle without
                 * another round trip. A tier half-listed in the Lemon Squeezy
                 * store — monthly created, yearly not yet — would otherwise
                 * offer a button that can only ever come back as an error
                 * toast.
                 */
                const purchasable = plan.prices[variant]?.purchasable === true;

                return (
                    <button
                        className={ctaClass(emphasised)}
                        disabled={!ready || !purchasable || checkout.processing}
                        onClick={() => startCheckout(plan)}
                    >
                        {!ready
                            ? 'Checkout unavailable'
                            : purchasable
                              ? plan.cta.label
                              : 'Not yet available'}
                    </button>
                );
            }

            case 'manage':
                return (
                    <Link href={editBilling()} className={ctaClass(false)}>
                        {plan.cta.label}
                    </Link>
                );

            case 'switch': {
                /*
                 * The same per-period check checkout makes, for the same
                 * reason: the card was decided once, and the toggle moves under
                 * it without asking the server again.
                 */
                const purchasable = plan.prices[variant]?.purchasable === true;

                return (
                    <ConfirmDialog
                        trigger={
                            <button
                                className={ctaClass(emphasised)}
                                disabled={!purchasable}
                            >
                                {purchasable
                                    ? plan.cta.label
                                    : 'Not yet available'}
                            </button>
                        }
                        title={`${plan.cta.label}?`}
                        description={`Your subscription moves to ${plan.label}, ${variant === 'yearly' ? 'billed yearly' : 'billed monthly'}. Nothing is charged today — the difference between what you have paid for and what you are moving to is settled on your next renewal.`}
                        confirmLabel={plan.cta.label}
                        pendingLabel="Changing…"
                        onConfirm={() => switchPlan(plan)}
                    />
                );
            }

            default:
                return (
                    <button className={ctaClass(false)} disabled>
                        {plan.cta.label}
                    </button>
                );
        }
    };

    const renderCard = (plan: PricingPlan) => (
        <div
            key={plan.value}
            className={cn(
                'flex h-full flex-col p-8',
                plan.isPopular
                    ? 'border border-primary bg-primary/5'
                    : 'border border-border bg-white/[0.02]',
            )}
        >
            {/* Fixed height so the badge on one card does not sit its whole
                column lower than the rest. */}
            <div className="mb-2 flex h-6 items-center justify-between gap-2">
                <div className="font-mono text-xs tracking-widest text-primary uppercase">
                    {plan.label}
                </div>
                {plan.isPopular && (
                    <span className="bg-primary px-2 py-0.5 font-mono text-[10px] tracking-widest text-primary-foreground uppercase">
                        Most popular
                    </span>
                )}
                {plan.isCurrent && (
                    <span className="border border-border px-2 py-0.5 font-mono text-[10px] tracking-widest text-muted-foreground uppercase">
                        Current
                    </span>
                )}
            </div>

            {/* Reserved so a three-line tagline does not push one card's price
                below its neighbours'. */}
            <p className="mb-8 min-h-[4.5rem] text-sm leading-relaxed text-muted-foreground">
                {plan.tagline}
            </p>

            <div className="mb-8">
                <PlanPriceLine plan={plan} variant={variant} />
            </div>

            <ul className="mb-8 flex-1 space-y-4 text-sm">
                {plan.highlights.map((highlight) => (
                    <li
                        key={highlight}
                        className="flex gap-3 text-muted-foreground"
                    >
                        <span aria-hidden className="text-primary">
                            ✓
                        </span>
                        <span className="leading-relaxed">{highlight}</span>
                    </li>
                ))}
            </ul>

            {renderCta(plan)}
        </div>
    );

    return (
        <>
            <Head title="Pricing" />

            <div className="theme-droneverse dark min-h-screen bg-background font-sans text-foreground selection:bg-primary selection:text-primary-foreground">
                <SiteHeader current="pricing" />

                <main className="mx-auto max-w-7xl space-y-24 px-6 py-24">
                    <section>
                        <SectionLabel>Pricing</SectionLabel>

                        <div className="mb-12 max-w-3xl">
                            <h1 className="mb-6 text-5xl font-extrabold tracking-tighter text-balance uppercase lg:text-6xl">
                                Fly free.{' '}
                                <span className="text-muted-foreground">
                                    Pay when you outgrow it.
                                </span>
                            </h1>
                            <p className="text-lg leading-relaxed text-muted-foreground">
                                Three beginner courses and five missions cost
                                nothing and never expire. Everything below is
                                for when you want the rest of the catalogue and
                                the tools that come with it.
                            </p>
                        </div>

                        <div className="mb-8">
                            <BillingPeriodToggle
                                variant={variant}
                                onChange={setVariant}
                                saving={annualSaving}
                            />
                        </div>

                        <div className="grid gap-1 md:grid-cols-2 lg:grid-cols-4">
                            {plans.map(renderCard)}
                        </div>
                    </section>

                    <section>
                        <SectionLabel>What each plan unlocks</SectionLabel>

                        <div className="mb-12 max-w-3xl">
                            <h2 className="mb-4 text-3xl font-bold tracking-tighter uppercase lg:text-4xl">
                                Capability by capability.
                            </h2>
                            <p className="leading-relaxed text-muted-foreground">
                                Capabilities still in the hangar are marked as
                                such. They are included the day they ship, at no
                                extra cost, on every plan below that grants
                                them.
                            </p>
                        </div>

                        {/*
                         * A matrix, not a list of records, so it stays on the
                         * Table primitives rather than DataTable: every row
                         * leads with a `scope="row"` header, and re-sorting
                         * capabilities alphabetically would help nobody.
                         *
                         * The container carries role/name/tabIndex because it
                         * scrolls — at `min-w-3xl` the right-hand plans are
                         * off-screen on a phone, and without a tab stop a
                         * keyboard user cannot reach them.
                         */}
                        <div
                            role="region"
                            aria-labelledby={comparisonCaptionId}
                            tabIndex={0}
                            className="overflow-x-auto border border-border bg-white/[0.02] focus-visible:ring-3 focus-visible:ring-ring/50 focus-visible:outline-none"
                        >
                            <Table className="min-w-3xl">
                                <TableCaption
                                    id={comparisonCaptionId}
                                    className="sr-only"
                                >
                                    Capabilities granted by each plan
                                </TableCaption>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead className="p-4 font-mono text-xs tracking-widest text-muted-foreground uppercase">
                                            Capability
                                        </TableHead>
                                        {plans.map((plan) => (
                                            <TableHead
                                                key={plan.value}
                                                className="p-4 text-center font-mono text-xs tracking-widest text-primary uppercase"
                                            >
                                                {plan.label}
                                            </TableHead>
                                        ))}
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {comparison.map((row) => (
                                        <TableRow key={row.value}>
                                            <TableHead
                                                scope="row"
                                                className="p-4 text-sm font-normal whitespace-normal text-foreground"
                                            >
                                                {row.label}
                                                {!row.available && (
                                                    <span className="ml-2 font-mono text-[10px] tracking-widest text-muted-foreground uppercase">
                                                        coming soon
                                                    </span>
                                                )}
                                            </TableHead>
                                            {row.plans.map((granted, index) => (
                                                <TableCell
                                                    key={plans[index].value}
                                                    className="p-4 text-center"
                                                >
                                                    {granted ? (
                                                        <Check
                                                            aria-label="Included"
                                                            className="mx-auto size-4 text-primary"
                                                        />
                                                    ) : (
                                                        <Minus
                                                            aria-label="Not included"
                                                            className="mx-auto size-4 text-muted-foreground/50"
                                                        />
                                                    )}
                                                </TableCell>
                                            ))}
                                        </TableRow>
                                    ))}
                                </TableBody>
                            </Table>
                        </div>
                    </section>

                    <section>
                        <SectionLabel>Questions</SectionLabel>

                        <dl className="grid gap-1 md:grid-cols-2">
                            {FAQS.map((faq) => (
                                <div
                                    key={faq.question}
                                    className="border border-border bg-white/[0.02] p-6 transition-colors hover:bg-white/[0.04] md:p-8"
                                >
                                    <dt className="mb-3 text-xl font-bold tracking-tight uppercase">
                                        {faq.question}
                                    </dt>
                                    <dd className="text-sm leading-relaxed text-muted-foreground">
                                        {faq.answer}
                                    </dd>
                                </div>
                            ))}
                        </dl>
                    </section>

                    <section className="border border-primary bg-primary/5 p-8 md:p-12">
                        <h2 className="mb-4 text-3xl font-bold tracking-tighter uppercase">
                            Still deciding?
                        </h2>
                        <p className="mb-8 max-w-xl leading-relaxed text-muted-foreground">
                            Fly the five free missions first. Nothing on this
                            page expires, and nothing here is needed to find out
                            whether you like it.
                        </p>
                        <div className="flex flex-wrap gap-4">
                            <Link
                                href={auth.user ? coursesIndex() : register()}
                                className="bg-primary px-6 py-4 font-bold tracking-widest text-primary-foreground uppercase transition-all hover:brightness-110"
                            >
                                {auth.user
                                    ? 'Browse courses'
                                    : 'Create your free account'}
                            </Link>
                        </div>
                    </section>
                </main>

                <SiteFooter />
            </div>
        </>
    );
}
