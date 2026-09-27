import { Head, Link, router, usePage } from '@inertiajs/react';
import { Check, Minus } from 'lucide-react';
import { useId, useState } from 'react';
import CheckoutController from '@/actions/App/Http/Controllers/CheckoutController';
import {
    BillingPeriodToggle,
    periodSuffix,
} from '@/components/billing-period-toggle';
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
import { cn } from '@/lib/utils';
import { dashboard, register } from '@/routes';
import { index as coursesIndex } from '@/routes/courses';
import type {
    PlanComparisonRow,
    PlanVariant,
    PricingPlan,
} from '@/types/billing';

type PricingProps = {
    plans: PricingPlan[];
    comparison: PlanComparisonRow[];
};

const FAQS = [
    {
        question: 'Can I switch between monthly and yearly?',
        answer: 'Yes, at any time. Open Manage billing in your account settings to move between monthly and yearly billing, update your card or download invoices.',
    },
    {
        question: 'What is Lifetime?',
        answer: 'Pro, paid for once. There is nothing to renew or cancel, and everything Pro includes stays on your account.',
    },
    {
        question: 'Can I cancel my subscription?',
        answer: 'Any time, from Manage billing in your account settings. Cancelling schedules the end of the period you have already paid for; nothing is taken away before then.',
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
 * The price line, or the absence of one.
 *
 * Starter carries no amount at all and says so in words — a card showing a
 * blank where a number belongs reads as a page that failed to load. It is the
 * only tier that should reach that branch: every paid plan is priced in
 * config/plans.php and tests/Unit/PlanTest.php holds them to it, so a paid card
 * arriving here is a misconfiguration, and it must not read as free.
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
                {plan.value === 'starter' ? 'Free' : 'Unavailable'}
            </p>
        );
    }

    return (
        <p className="text-4xl font-extrabold tracking-tighter">
            {price.formatted}
            <span className="text-lg font-normal text-muted-foreground">
                {periodSuffix(variant)}
            </span>
        </p>
    );
}

export default function Pricing({ plans, comparison }: PricingProps) {
    const { auth } = usePage().props;
    const [variant, setVariant] = useState<PlanVariant>('monthly');
    const [processing, setProcessing] = useState(false);
    const comparisonCaptionId = useId();

    const annualSaving =
        plans.find((plan) => plan.prices.yearly?.savingPercent)?.prices.yearly
            ?.savingPercent ?? null;

    /**
     * Ask the server to open a checkout, and follow it there.
     *
     * The request carries a tier and a period; the price it resolves to is the
     * server's business. It answers with Kelviq's hosted checkout as an Inertia
     * location, which Inertia follows with a full navigation — or with a flashed
     * toast and a redirect back, which the global flash handler shows.
     */
    const startCheckout = (plan: PricingPlan) => {
        router.post(
            CheckoutController.store.url(),
            { plan: plan.value, variant },
            {
                preserveScroll: true,
                onStart: () => setProcessing(true),
                onFinish: () => setProcessing(false),
            },
        );
    };

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

            case 'checkout': {
                /*
                 * The server decides that the tier is buyable by this viewer;
                 * whether the period they are looking at is buyable is a
                 * separate answer, and it changes under the toggle without
                 * another round trip.
                 */
                const purchasable = plan.prices[variant]?.purchasable === true;

                return (
                    <button
                        className={ctaClass(emphasised)}
                        disabled={!purchasable || processing}
                        onClick={() => startCheckout(plan)}
                    >
                        {purchasable ? plan.cta.label : 'Not yet available'}
                    </button>
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

                        <div className="grid max-w-4xl gap-1 md:grid-cols-2">
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
