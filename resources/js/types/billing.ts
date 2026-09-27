import type { FeatureValue, PlanValue } from '@/types/auth';

/** A way of paying for a plan, as named in config/plans.php. */
export type PlanVariant = 'monthly' | 'yearly' | 'lifetime';

/**
 * What a plan's button does.
 *
 * Decided server-side by App\Actions\BuildPricingCatalog — whether a tier is
 * for sale is a question about configuration and entitlements, and the page is
 * not in a position to answer either.
 */
export type PlanCtaAction =
    'signup' | 'current' | 'included' | 'checkout' | 'unavailable';

export type PlanPrice = {
    /** Minor units, for comparisons the page would rather not parse back out. */
    amount: number;
    formatted: string;
    /** Only ever set on the yearly variant. */
    savingPercent: number | null;
    /**
     * Whether a checkout can be opened on this period in this environment.
     * A quoted price is not an offer.
     */
    purchasable: boolean;
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
 * Why the pilot holds the plan they hold: granted by hand, billed by Kelviq,
 * or neither.
 */
export type BillingPlanSource = 'override' | 'kelviq' | 'none';

export type BillingPlan = {
    value: PlanValue;
    label: string;
    isPaid: boolean;
    source: BillingPlanSource;
};

/** The plan a pilot on Starter is offered from billing settings. */
export type BillingUpgrade = {
    value: PlanValue;
    label: string;
    tagline: string;
    highlights: string[];
    prices: Partial<Record<PlanVariant, PlanPrice>>;
};
