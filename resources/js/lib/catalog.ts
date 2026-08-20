import type { PlanValue } from '@/types/auth';

/**
 * How the public catalogue talks about plans and progress.
 *
 * The rules here are presentational and they are the same on every surface
 * that shows a tier — the catalogue, a course page, an upgrade prompt — so
 * they live in one module rather than being restated as a ternary per card.
 */

/** Mirrors `App\Enums\Plan::label()`. */
const PLAN_LABEL: Record<PlanValue, string> = {
    starter: 'Starter',
    pro: 'Pro',
    team: 'Team',
    enterprise: 'Enterprise',
};

export function planLabel(plan: PlanValue): string {
    return PLAN_LABEL[plan];
}

/**
 * What a tier is called on a page a signed-out visitor might land on.
 *
 * Starter reads as "Free" here rather than by its name. A visitor who has not
 * been to the pricing page has no idea what Starter is, and the one thing the
 * public catalogue has to communicate is which courses cost nothing.
 */
export function tierLabel(requiredPlan: PlanValue): string {
    return requiredPlan === 'starter' ? 'Free' : PLAN_LABEL[requiredPlan];
}

/** Whether reaching this tier means paying for it. */
export function isPaidTier(requiredPlan: PlanValue): boolean {
    return requiredPlan !== 'starter';
}

/**
 * What a catalogue card says its missions cost, from the counts alone.
 *
 * One line rather than a count and a tier badge saying different things: the
 * card has room for one sentence about what flying this costs, and "9 missions"
 * on its own is the fact nobody is asking for.
 */
export function courseCostSummary(
    free: number,
    total: number,
    missionPlan: PlanValue | null,
): string {
    if (total <= 0) {
        return 'No missions published yet';
    }

    const missions = `${total} ${total === 1 ? 'mission' : 'missions'}`;

    if (missionPlan === null || free >= total) {
        return `${missions} · all free`;
    }

    if (free <= 0) {
        return `${missions} · ${planLabel(missionPlan)}`;
    }

    return `${missions} · ${free} free, ${total - free} with ${planLabel(missionPlan)}`;
}

/**
 * One line saying what a course's missions cost, from the tier each sits in.
 *
 * Asked of the missions rather than of the course because the two disagree
 * often and on purpose: three of the seeded courses are Starter courses whose
 * missions are Pro past the first few, and a header reading "Free to fly" over
 * a list of locked rows is the kind of promise a chargeback starts with.
 *
 * Null when there is nothing to summarise — an empty course says nothing here
 * rather than claiming everything in it is free.
 */
export function missionCostSummary(tiers: PlanValue[]): string | null {
    if (tiers.length === 0) {
        return null;
    }

    const paid = tiers.filter(isPaidTier);

    if (paid.length === 0) {
        return 'Every mission free';
    }

    // The first paid tier names them all. A course mixing Pro and Team
    // missions is not a shape the catalogue sells, and naming the cheaper of
    // the two is the error that leaves a buyer able to fly what they paid for.
    const plan = planLabel(paid[0]);

    if (paid.length === tiers.length) {
        return `Every mission with ${plan}`;
    }

    return `${tiers.length - paid.length} free · ${paid.length} with ${plan}`;
}

/**
 * How far through a course a pilot is, as a whole percentage.
 *
 * A course with nothing published in it is 0 rather than NaN: NaN reaches the
 * DOM as `width: NaN%`, which the browser drops, leaving a bar that keeps
 * whatever width it last had instead of showing none.
 */
export function completionPercent(completed: number, total: number): number {
    if (!Number.isFinite(completed) || !Number.isFinite(total) || total <= 0) {
        return 0;
    }

    return Math.round(Math.min(Math.max(completed / total, 0), 1) * 100);
}
