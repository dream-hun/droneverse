import { usePage } from '@inertiajs/react';
import type { FeatureValue, Plan, PlanValue } from '@/types/auth';

export type UsePlanReturn = {
    /** The viewer's resolved plan. Guests resolve to Starter. */
    plan: Plan;
    /** Every feature the plan grants. */
    features: FeatureValue[];
    /** Whether the plan grants a given feature. */
    hasFeature: (feature: FeatureValue) => boolean;
    /** Whether the viewer is on anything other than Starter. */
    onPaidPlan: boolean;
    /** Whether the viewer is on a specific plan. */
    isPlan: (plan: PlanValue) => boolean;
};

const STARTER: Plan = { value: 'starter', label: 'Starter', isPaid: false };

/**
 * Read the entitlements the server resolved for this request.
 *
 * Use it to show locks, upgrade CTAs and disabled controls — never to decide
 * whether an action is allowed. Every gated capability is enforced by its Gate
 * on the server, and this hook only reflects what that server already decided.
 */
export function usePlan(): UsePlanReturn {
    const { auth } = usePage().props;

    const plan = auth?.plan ?? STARTER;
    const features = auth?.features ?? [];

    return {
        plan,
        features,
        hasFeature: (feature: FeatureValue) => features.includes(feature),
        onPaidPlan: plan.isPaid,
        isPlan: (candidate: PlanValue) => plan.value === candidate,
    };
}
