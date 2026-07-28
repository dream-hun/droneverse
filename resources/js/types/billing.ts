import type { FeatureValue, PlanValue } from '@/types/auth';

/** A billing period, as named in config/plans.php. */
export type PlanVariant = 'monthly' | 'yearly';

/**
 * What a plan's button does.
 *
 * Decided server-side by App\Actions\BuildPricingCatalog — whether a tier is
 * for sale is a question about configuration and entitlements, and the page is
 * not in a position to answer either.
 */
export type PlanCtaAction =
    'signup' | 'current' | 'included' | 'contact' | 'checkout' | 'unavailable';

export type PlanPrice = {
    /** Minor units, for comparisons the page would rather not parse back out. */
    amount: number;
    formatted: string;
    /** Only ever set on the yearly variant. */
    savingPercent: number | null;
};

export type PricingPlan = {
    value: PlanValue;
    label: string;
    tagline: string;
    highlights: string[];
    prices: Partial<Record<PlanVariant, PlanPrice>>;
    isCurrent: boolean;
    isPopular: boolean;
    cta: { action: PlanCtaAction; label: string };
};

export type PlanComparisonRow = {
    value: FeatureValue;
    label: string;
    /** False while the capability is sold but unbuilt. */
    available: boolean;
    /** One flag per plan, in the same order as the plans array. */
    plans: boolean[];
};

/**
 * Enough to boot Paddle.js. A null token means Paddle is unconfigured here and
 * no checkout can open, whatever the cards say.
 */
export type PaddleConfig = {
    token: string | null;
    sandbox: boolean;
};

/** Why the pilot holds the plan they hold. */
export type BillingPlanSource = 'override' | 'subscription' | 'none';

export type BillingPlan = {
    value: PlanValue;
    label: string;
    isPaid: boolean;
    source: BillingPlanSource;
};

export type BillingSubscription = {
    status: string;
    planLabel: string | null;
    variant: string | null;
    valid: boolean;
    onGracePeriod: boolean;
    canceled: boolean;
    paused: boolean;
    pastDue: boolean;
    onTrial: boolean;
    endsAt: string | null;
    trialEndsAt: string | null;
    /** Null whenever Paddle would not answer — never treat it as "no charge". */
    nextPayment: { amount: string; date: string | null } | null;
};

export type BillingTransaction = {
    id: string;
    invoiceNumber: string | null;
    status: string;
    total: string;
    billedAt: string | null;
};
