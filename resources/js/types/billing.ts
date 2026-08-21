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
    | 'signup'
    | 'current'
    | 'included'
    | 'checkout'
    /** Move the subscription this viewer already holds onto this plan. */
    | 'switch'
    /**
     * Their subscription needs attention before any plan can change — it is
     * cancelled and running out its grace period, which is neither switchable
     * nor a state to sell a second subscription into.
     */
    | 'manage'
    | 'unavailable';

export type PlanPrice = {
    /** Minor units, for comparisons the page would rather not parse back out. */
    amount: number;
    formatted: string;
    /** Only ever set on the yearly variant. */
    savingPercent: number | null;
    /**
     * Whether a checkout can be opened on this period specifically.
     *
     * Separate from `cta.action`, which is decided once per card while the
     * period is picked afterwards by the toggle: a tier can sell monthly and
     * not yearly, and only this says so. A quoted price is not an offer.
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
 * Whether a checkout can open at all in this environment.
 *
 * Creem's embed script needs no public token: everything a checkout needs is
 * baked into the URL the server mints, so the only thing the page has to know
 * is whether an API key is configured behind it. False means no checkout can
 * open, whatever the cards say.
 */
export type CreemConfig = {
    configured: boolean;
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
    cancelled: boolean;
    paused: boolean;
    pastDue: boolean;
    onTrial: boolean;
    endsAt: string | null;
    trialEndsAt: string | null;
    /**
     * When the subscription bills again, mirrored locally from the webhook.
     *
     * A date and nothing else: Creem publishes no forthcoming amount, so the
     * page can say when the next charge lands but never how much it is.
     */
    renewsAt: string | null;
};

/** One billing period a subscription could be moved onto. */
export type SwitchableVariant = {
    value: PlanVariant;
    /** Minor units, for comparisons the page would rather not parse back out. */
    amount: number;
    formatted: string;
    /** Whether the subscription already sells exactly this price. */
    isCurrent: boolean;
};

/**
 * A plan a subscriber can move to, with the periods that can be moved onto.
 *
 * Only periods with a configured price appear, so anything listed here can
 * actually be switched to. An empty array means there is nothing to switch —
 * no subscription, or one already winding down — and the page offers no
 * change-plan control at all.
 */
export type SwitchablePlan = {
    value: PlanValue;
    label: string;
    tagline: string;
    variants: SwitchableVariant[];
};

/** One paid order, i.e. one receipt. */
export type BillingOrder = {
    /** Creem's own ID for the order, which is what support asks for. */
    id: string;
    status: string;
    /** Pre-formatted by the server, currency and all. */
    total: string;
    refunded: boolean;
    /**
     * How much came back, pre-formatted, when some of it did.
     *
     * Beside the flag rather than instead of it because Creem allows partial
     * refunds: "refunded" alone would tell a pilot their whole year came back
     * when a month did. Null on an order nothing was refunded from, and on one
     * refunded before this column existed.
     */
    refundedTotal: string | null;
    orderedAt: string | null;
};

/**
 * What a completed checkout is confirmed with, from
 * App\Actions\BuildSubscriptionConfirmation.
 *
 * Only ever present once the webhook has written the subscription, which is
 * why every field is a fact rather than a promise: the page has nothing to
 * report until there is a row to report it from.
 */
export type SubscriptionConfirmation = {
    planLabel: string | null;
    variant: string | null;
    onTrial: boolean;
    trialEndsAt: string | null;
    /** The date the next charge lands. Creem publishes no amount. */
    renewsAt: string | null;
};
