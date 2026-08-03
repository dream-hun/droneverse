import { Link } from '@inertiajs/react';
import { CheckCircle2, Circle, Lock, PlayCircle } from 'lucide-react';
import { PlanLockBadge, PlanUpgradeHint } from '@/components/plan-lock-badge';
import { StarRating } from '@/components/star-rating';
import { Badge } from '@/components/ui/badge';
import { Card, CardHeader, CardTitle } from '@/components/ui/card';
import { Skeleton } from '@/components/ui/skeleton';
import { cn } from '@/lib/utils';
import { show as showChallenge } from '@/routes/challenges';
import type { ChallengeStatus, ChallengeSummary } from '@/types/simulator';

const STATUS_ICON: Record<ChallengeStatus, typeof Circle> = {
    not_started: Circle,
    in_progress: PlayCircle,
    completed: CheckCircle2,
};

type ChallengeRowProps = {
    challenge: ChallengeSummary;
    courseSlug: string;
    /** Zero-based; displayed as the mission's position in the course. */
    index: number;
};

/** One mission in a course, as a full-width row. */
export function ChallengeRow({
    challenge,
    courseSlug,
    index,
}: ChallengeRowProps) {
    const Icon = challenge.locked ? Lock : STATUS_ICON[challenge.status];

    const card = (
        <Card
            className={
                challenge.locked
                    ? 'border-dashed'
                    : 'transition-shadow hover:shadow-md'
            }
        >
            <CardHeader className="flex-row items-center justify-between gap-3 space-y-0">
                <div className="flex min-w-0 items-center gap-3">
                    <Icon
                        aria-hidden="true"
                        className="size-5 shrink-0 text-muted-foreground"
                    />
                    <div className="min-w-0">
                        <CardTitle
                            className={cn(
                                'text-base',
                                challenge.locked && 'text-muted-foreground',
                            )}
                        >
                            {index + 1}. {challenge.title}
                        </CardTitle>
                        {challenge.locked ? (
                            <PlanUpgradeHint plan={challenge.requiredPlan} />
                        ) : (
                            <p className="text-xs text-muted-foreground capitalize">
                                {challenge.difficulty}
                            </p>
                        )}
                    </div>
                </div>
                <div className="flex shrink-0 items-center gap-2">
                    {challenge.locked && (
                        <PlanLockBadge asLink plan={challenge.requiredPlan} />
                    )}
                    {challenge.stars > 0 && (
                        <StarRating value={challenge.stars} />
                    )}
                    {challenge.bestScore > 0 && (
                        <Badge variant="outline">{challenge.bestScore}</Badge>
                    )}
                </div>
            </CardHeader>
        </Card>
    );

    // A locked mission is shown in full but is not a link: the route would turn
    // the pilot away, and a link that 403s reads as a bug rather than a paywall.
    if (challenge.locked) {
        return card;
    }

    return (
        <Link
            href={showChallenge([courseSlug, challenge.slug])}
            className="block rounded-xl outline-none focus-visible:ring-[3px] focus-visible:ring-ring/50"
        >
            {card}
        </Link>
    );
}

/** Layout-stable placeholder matching a single `ChallengeRow`. */
export function ChallengeRowSkeleton() {
    return (
        <Card aria-hidden="true">
            <CardHeader className="flex-row items-center justify-between gap-3 space-y-0">
                <div className="flex min-w-0 items-center gap-3">
                    <Skeleton className="size-5 shrink-0 rounded-full" />
                    <div className="space-y-1.5">
                        <Skeleton className="h-4 w-48" />
                        <Skeleton className="h-3 w-20" />
                    </div>
                </div>
                <Skeleton className="h-5 w-16 shrink-0" />
            </CardHeader>
        </Card>
    );
}
