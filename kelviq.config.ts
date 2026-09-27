// kelviq.config.ts — the Droneverse catalog as pricing-as-code.
//
// Sync with the sandbox using `npx kelviq push --dry-run` / `npx kelviq push`,
// and copy it to production with `npx kelviq promote`. References between
// entries are identifiers, never UUIDs.
//
// Only capabilities that have shipped are listed here. The feature identifiers
// are the ones App\Enums\Feature::kelviqId() asks Kelviq about, and
// `full-catalog` is what App\Actions\ResolvePlanForUser reads as "on Pro".
// Starter is not a Kelviq plan: it is the free tier every account has until
// Kelviq says otherwise, so nobody is enrolled with Kelviq at signup.
import { feature, plan, product } from '@kelviq/cli/config';

export const droneverse = product({
    identifier: 'droneverse',
    name: 'Droneverse',
    description: 'Learn to program drones by flying missions in the browser.',
    taxCode: 'saas',
});

export const fullCatalog = feature({
    identifier: 'full-catalog',
    name: 'Full catalogue',
    description: 'Every course and every mission.',
    type: 'BOOLEAN',
});

export const droneConfigEditor = feature({
    identifier: 'drone-config-editor',
    name: 'Drone Configuration Editor',
    description: 'Pick your airframe on any mission.',
    type: 'BOOLEAN',
});

export const advancedAnalytics = feature({
    identifier: 'advanced-analytics',
    name: 'Advanced Analytics',
    description: 'The analytics page and the flight log on every run.',
    type: 'BOOLEAN',
});

export const prioritySupport = feature({
    identifier: 'priority-support',
    name: 'Priority Support',
    type: 'BOOLEAN',
});

export const betaAccess = feature({
    identifier: 'beta-access',
    name: 'Beta Access',
    type: 'BOOLEAN',
});

export const pro = plan({
    identifier: 'pro',
    name: 'Pro',
    product: 'droneverse',
    description:
        'The whole catalogue, every mission in it, and the tools that come with them.',
    entitlements: [
        { feature: 'full-catalog', value: true },
        { feature: 'drone-config-editor', value: true },
        { feature: 'advanced-analytics', value: true },
        { feature: 'priority-support', value: true },
        { feature: 'beta-access', value: true },
    ],
    prices: [
        {
            priceType: 'PAID',
            currency: 'USD',
            freeTrial: false,
            trialPeriod: 0,
            enabled: true,
            // Tax-inclusive, as Kelviq stores this plan: the price on the
            // pricing page is what the customer pays, as /terms promises.
            taxBehavior: 'INCLUSIVE',
            chargeCatalogPrice: [
                {
                    feature: null,
                    priceModel: 'FLAT',
                    paymentType: 'ADVANCE_COMMITMENT',
                    reset: 'NEVER',
                    // Kelviq's own defaults, stated so a push compares equal
                    // to what Kelviq stores instead of replacing the price.
                    resetTime: null,
                    hasUnlimitedUsage: false,
                    rollover: {},
                    usageAlerts: {},
                    charges: [
                        {
                            chargePeriod: 'MONTHLY',
                            priceData: { amount: 19 },
                            tiers: [],
                            advanced: {},
                        },
                        {
                            chargePeriod: 'YEARLY',
                            priceData: { amount: 190 },
                            tiers: [],
                            advanced: {},
                        },
                    ],
                },
            ],
        },
    ],
});

// Pro, paid for once. Its own plan rather than a third charge on `pro`, so
// codes scoped to `pro` (LAUNCH20) do not discount it, and so a one-time sale
// never shares a price with a renewal. It grants exactly what Pro grants.
export const proLifetime = plan({
    identifier: 'pro-lifetime',
    name: 'Pro Lifetime',
    product: 'droneverse',
    description: 'Everything in Pro, paid for once.',
    entitlements: [
        { feature: 'full-catalog', value: true },
        { feature: 'drone-config-editor', value: true },
        { feature: 'advanced-analytics', value: true },
        { feature: 'priority-support', value: true },
        { feature: 'beta-access', value: true },
    ],
    prices: [
        {
            priceType: 'PAID',
            currency: 'USD',
            freeTrial: false,
            trialPeriod: 0,
            enabled: true,
            // Tax-inclusive, as Kelviq stores this plan: the price on the
            // pricing page is what the customer pays, as /terms promises.
            taxBehavior: 'INCLUSIVE',
            chargeCatalogPrice: [
                {
                    feature: null,
                    priceModel: 'FLAT',
                    paymentType: 'ADVANCE_COMMITMENT',
                    reset: 'NEVER',
                    resetTime: null,
                    hasUnlimitedUsage: false,
                    rollover: {},
                    usageAlerts: {},
                    charges: [
                        {
                            chargePeriod: 'ONE_TIME',
                            priceData: { amount: 350 },
                            tiers: [],
                            advanced: {},
                        },
                    ],
                },
            ],
        },
    ],
});
