import { Link } from '@inertiajs/react';
import { Lock } from 'lucide-react';
import { Badge } from '@/components/ui/badge';
import { planLabel } from '@/lib/catalog';
import { pricing } from '@/routes';
import type { PlanValue } from '@/types/auth';

/**
 * Marks content the viewer's plan does not reach, and offers the way out.
 *
 * Rendered as a plain badge rather than a link when `asLink` is false, which is
 * how the course card uses it: the whole card is already a link to the course,
 * and a link inside a link is invalid markup that browsers resolve by guessing.
 */
export function PlanLockBadge({
    plan,
    asLink = false,
}: {
    plan: PlanValue;
    asLink?: boolean;
}) {
    const badge = (
        <Badge variant="secondary" className="shrink-0">
            <Lock aria-hidden="true" data-icon="inline-start" />
            {planLabel(plan)}
        </Badge>
    );

    if (!asLink) {
        return badge;
    }

    return (
        <Link
            href={pricing()}
            aria-label={`Locked — included with ${planLabel(plan)}. See plans.`}
            className="rounded-md outline-none focus-visible:ring-[3px] focus-visible:ring-ring/50"
        >
            {badge}
        </Link>
    );
}

/**
 * The line shown in place of a locked mission's call to action.
 */
export function PlanUpgradeHint({ plan }: { plan: PlanValue }) {
    return (
        <p className="text-xs text-muted-foreground">
            Included with{' '}
            <Link
                href={pricing()}
                className="underline decoration-muted-foreground/50 underline-offset-4 transition-colors hover:text-foreground hover:decoration-current"
            >
                {planLabel(plan)}
            </Link>
        </p>
    );
}
