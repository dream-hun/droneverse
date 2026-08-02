import { Head, Link, router, useHttp, usePage } from '@inertiajs/react';
import { ArrowRight, Check, Minus } from 'lucide-react';
import { useCallback, useEffect, useId, useRef, useState } from 'react';
import { toast } from 'sonner';
import CheckoutController from '@/actions/App/Http/Controllers/CheckoutController';
import AppLogoIcon from '@/components/app-logo-icon';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Table,
    TableBody,
    TableCaption,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { usePaddle } from '@/hooks/use-paddle';
import { cn } from '@/lib/utils';
import { dashboard, login, register } from '@/routes';
import { index as coursesIndex } from '@/routes/courses';
import type {
    PaddleConfig,
    PlanComparisonRow,
    PlanVariant,
    PricingPlan,
} from '@/types/billing';

type PricingProps = {
    plans: PricingPlan[];
    comparison: PlanComparisonRow[];
    salesEmail: string | null;
    paddle: PaddleConfig;
};

type CheckoutResponse = {
    checkout: Record<string, unknown>;
};

const FAQS = [
    {
        question: 'Can I upgrade later?',
        answer: 'Yes. Move from Starter to Pro whenever you like — your progress, photos and scores come with you.',
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
            className="inline-flex items-center gap-1 rounded-full border border-border bg-card p-1"
        >
            {options.map((option) => (
                <Button
                    key={option.value}
                    type="button"
                    size="sm"
                    variant={variant === option.value ? 'default' : 'ghost'}
                    aria-pressed={variant === option.value}
                    className="h-8 rounded-full px-4"
                    onClick={() => onChange(option.value)}
                >
                    {option.label}
                    {option.value === 'yearly' && saving !== null && (
                        <span
                            className={cn(
                                'ml-1.5 text-xs',
                                variant === 'yearly'
                                    ? 'text-primary-foreground/80'
                                    : 'text-muted-foreground',
                            )}
                        >
                            save {saving}%
                        </span>
                    )}
                </Button>
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
            <p className="font-heading text-4xl font-semibold tracking-tight">
                {plan.value === 'starter' ? 'Free' : 'Custom'}
            </p>
        );
    }

    return (
        <p className="flex items-baseline gap-1.5">
            <span className="font-heading text-4xl font-semibold tracking-tight">
                {price.formatted}
            </span>
            <span className="text-sm text-muted-foreground">
                {variant === 'yearly' ? '/year' : '/month'}
            </span>
        </p>
    );
}

export default function Pricing({
    plans,
    comparison,
    salesEmail,
    paddle,
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
     * The overlay closes the moment Paddle has the money, which is not the
     * moment we know about it — the plan is granted by a webhook arriving
     * separately. Read through a ref so the callback below, registered once
     * when Paddle.js initialises, can still see the current one.
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

    /**
     * Say why the overlay gave up.
     *
     * Paddle's frame renders its own "Something went wrong" and closes on the
     * buyer's next click, so this is the only trace the page keeps of a
     * checkout that never opened.
     */
    const onCheckoutFailed = useCallback((message: string) => {
        toast.error(message);
    }, []);

    const { ready, openCheckout } = usePaddle(paddle, {
        onCompleted: onCheckoutCompleted,
        onFailed: onCheckoutFailed,
    });

    /**
     * Ask the server to open a checkout.
     *
     * The request carries a tier and a period; the price it resolves to is the
     * server's business. The overlay is opened from the response rather than
     * from anything this page knows.
     */
    const startCheckout = (plan: PricingPlan) => {
        checkout.setData({ plan: plan.value, variant });

        checkout.post(CheckoutController.store.url(), {
            onSuccess: (response) => openCheckout(response.checkout),
            onError: (errors) =>
                toast.error(
                    errors.plan ??
                        'Checkout could not be opened. Please try again.',
                ),
        });
    };

    const renderCta = (plan: PricingPlan) => {
        const emphasis = plan.isPopular ? 'default' : 'outline';

        switch (plan.cta.action) {
            case 'signup':
                return (
                    <Button asChild className="w-full" variant={emphasis}>
                        <Link href={auth.user ? dashboard() : register()}>
                            {plan.cta.label}
                        </Link>
                    </Button>
                );

            case 'contact':
                return salesEmail ? (
                    <Button asChild className="w-full" variant={emphasis}>
                        <a
                            href={`mailto:${salesEmail}?subject=${encodeURIComponent(`${plan.label} enquiry`)}`}
                        >
                            {plan.cta.label}
                        </a>
                    </Button>
                ) : (
                    <Button className="w-full" variant="outline" disabled>
                        {plan.cta.label}
                    </Button>
                );

            case 'checkout':
                return (
                    <Button
                        className="w-full"
                        variant={emphasis}
                        disabled={!ready || checkout.processing}
                        onClick={() => startCheckout(plan)}
                    >
                        {ready ? plan.cta.label : 'Checkout unavailable'}
                    </Button>
                );

            default:
                return (
                    <Button className="w-full" variant="outline" disabled>
                        {plan.cta.label}
                    </Button>
                );
        }
    };

    const renderCard = (plan: PricingPlan) => (
        <div
            key={plan.value}
            className={cn(
                'flex h-full flex-col rounded-xl border bg-card p-6',
                plan.isPopular
                    ? 'border-foreground/40 shadow-[0_20px_60px_-30px_oklch(0.145_0_0/0.5)]'
                    : 'border-border',
            )}
        >
            <div className="flex items-center justify-between gap-2">
                <h3 className="font-heading text-lg font-semibold tracking-tight">
                    {plan.label}
                </h3>
                {plan.isPopular && <Badge>Most popular</Badge>}
                {plan.isCurrent && <Badge variant="secondary">Current</Badge>}
            </div>

            <p className="mt-2 min-h-10 text-sm leading-relaxed text-muted-foreground">
                {plan.tagline}
            </p>

            <div className="mt-6">
                <PlanPriceLine plan={plan} variant={variant} />
            </div>

            <ul className="mt-6 flex-1 space-y-2.5 text-sm">
                {plan.highlights.map((highlight) => (
                    <li key={highlight} className="flex gap-2.5">
                        <Check
                            aria-hidden
                            className="mt-0.5 size-4 shrink-0 text-muted-foreground"
                        />
                        <span className="leading-relaxed">{highlight}</span>
                    </li>
                ))}
            </ul>

            <div className="mt-8">{renderCta(plan)}</div>
        </div>
    );

    return (
        <>
            <Head title="Pricing" />

            <div className="min-h-screen bg-[oklch(0.985_0_0)] text-foreground dark:bg-background">
                <header className="sticky top-0 z-30 border-b border-border/70 bg-[oklch(0.985_0_0)]/80 backdrop-blur dark:bg-background/80">
                    <nav className="mx-auto flex max-w-6xl items-center justify-between px-6 py-5">
                        <Link href="/" aria-label="DroneVerse home">
                            <span className="flex items-center gap-2.5 font-semibold tracking-tight">
                                <span className="flex aspect-square size-8 items-center justify-center rounded-md bg-foreground">
                                    <AppLogoIcon
                                        aria-hidden
                                        className="size-5 fill-current text-background"
                                    />
                                </span>
                                DroneVerse
                            </span>
                        </Link>

                        <div className="flex items-center gap-4 text-sm">
                            <Link
                                href={coursesIndex()}
                                className="hidden text-muted-foreground transition hover:text-foreground sm:inline"
                            >
                                Courses
                            </Link>
                            {auth.user ? (
                                <Link href={dashboard()}>
                                    <Button className="h-9 rounded-full px-4">
                                        Dashboard
                                    </Button>
                                </Link>
                            ) : (
                                <>
                                    <Link
                                        href={login()}
                                        className="text-muted-foreground transition hover:text-foreground"
                                    >
                                        Log in
                                    </Link>
                                    <Link href={register()}>
                                        <Button className="h-9 rounded-full px-4">
                                            Start free
                                        </Button>
                                    </Link>
                                </>
                            )}
                        </div>
                    </nav>
                </header>

                <main className="mx-auto max-w-6xl px-6 pt-16 pb-24">
                    <div className="max-w-2xl">
                        <h1 className="font-heading text-5xl leading-[1.05] font-semibold tracking-tight md:text-6xl">
                            Fly free. Pay when you outgrow it.
                        </h1>
                        <p className="mt-6 text-base leading-relaxed text-muted-foreground md:text-lg">
                            Three beginner courses and five missions cost
                            nothing and never expire. Everything below is for
                            when you want the rest of the catalogue and the
                            tools that come with it.
                        </p>
                    </div>

                    <div className="mt-10">
                        <BillingPeriodToggle
                            variant={variant}
                            onChange={setVariant}
                            saving={annualSaving}
                        />
                    </div>

                    <div className="mt-8 grid gap-4 md:grid-cols-2 lg:grid-cols-4">
                        {plans.map(renderCard)}
                    </div>

                    <section className="mt-24">
                        <h2 className="font-heading text-3xl font-semibold tracking-tight md:text-4xl">
                            What each plan unlocks
                        </h2>
                        <p className="mt-3 max-w-2xl text-sm text-muted-foreground">
                            Capabilities still in the hangar are marked as such.
                            They are included the day they ship, at no extra
                            cost, on every plan below that grants them.
                        </p>

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
                            className="mt-8 overflow-x-auto rounded-2xl border border-border bg-card focus-visible:ring-3 focus-visible:ring-ring/50 focus-visible:outline-none"
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
                                        <TableHead className="p-4 text-sm text-foreground">
                                            Capability
                                        </TableHead>
                                        {plans.map((plan) => (
                                            <TableHead
                                                key={plan.value}
                                                className="p-4 text-center text-sm text-foreground"
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
                                                    <span className="ml-2 text-xs text-muted-foreground">
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
                                                            className="mx-auto size-4"
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

                    <section className="mt-24">
                        <h2 className="font-heading text-3xl font-semibold tracking-tight md:text-4xl">
                            Questions
                        </h2>
                        <dl className="mt-8 grid gap-6 md:grid-cols-2">
                            {FAQS.map((faq) => (
                                <div
                                    key={faq.question}
                                    className="rounded-2xl border border-border bg-card p-6"
                                >
                                    <dt className="font-heading text-base font-semibold tracking-tight">
                                        {faq.question}
                                    </dt>
                                    <dd className="mt-2 text-sm leading-relaxed text-muted-foreground">
                                        {faq.answer}
                                    </dd>
                                </div>
                            ))}
                        </dl>
                    </section>

                    <section className="mt-24 border-t border-border/70 pt-16 text-center">
                        <h2 className="font-heading text-3xl font-semibold tracking-tight md:text-4xl">
                            Still deciding?
                        </h2>
                        <p className="mx-auto mt-4 max-w-xl text-sm text-muted-foreground md:text-base">
                            Fly the five free missions first. Nothing on this
                            page expires, and nothing here is needed to find out
                            whether you like it.
                        </p>
                        <div className="mt-8 flex justify-center">
                            <Link
                                href={auth.user ? coursesIndex() : register()}
                            >
                                <Button className="h-11 gap-2 rounded-full px-6">
                                    {auth.user
                                        ? 'Browse courses'
                                        : 'Create your free account'}
                                    <ArrowRight />
                                </Button>
                            </Link>
                        </div>
                    </section>
                </main>
            </div>
        </>
    );
}
