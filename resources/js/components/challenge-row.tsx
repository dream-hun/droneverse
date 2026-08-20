import { Link, usePage } from '@inertiajs/react';
import { CheckCircle2, Circle, Lock, PlayCircle } from 'lucide-react';
import { PILL } from '@/components/marketing/marketing-shell';
import { StarRating } from '@/components/star-rating';
import { Skeleton } from '@/components/ui/skeleton';
import { planLabel } from '@/lib/catalog';
import { cn } from '@/lib/utils';
import { pricing } from '@/routes';
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

/**
 * One mission in a course, as a full-width row.
 *
 * Drawn in the public palette, because the page that lists these is a public
 * one: square panels, small caps, and no card radius. The cockpit it links
 * into is the app shell, and the row says so — a signed-out visitor is told
 * that flying starts with an account rather than being walked into a login
 * redirect by a link that looked like it went to the mission.
 */
export function ChallengeRow({
    challenge,
    courseSlug,
    index,
}: ChallengeRowProps) {
    const { auth } = usePage().props;
    const signedIn = Boolean(auth.user);

    const Icon = challenge.locked ? Lock : STATUS_ICON[challenge.status];

    const row = (
        <div
            className={cn(
                'flex flex-col gap-4 border border-border bg-white/[0.02] p-6 sm:flex-row sm:items-center sm:justify-between',
                challenge.locked
                    ? 'border-dashed'
                    : 'transition-colors hover:bg-white/[0.04]',
            )}
        >
            <div className="flex min-w-0 items-start gap-5">
                <span
                    aria-hidden
                    className="font-mono text-sm text-muted-foreground tabular-nums"
                >
                    {String(index + 1).padStart(2, '0')}
                </span>

                <div className="min-w-0">
                    <div className="flex items-center gap-2">
                        <Icon
                            aria-hidden="true"
                            className={cn(
                                'size-4 shrink-0',
                                challenge.locked
                                    ? 'text-primary'
                                    : 'text-muted-foreground',
                            )}
                        />
                        <h3
                            className={cn(
                                'truncate text-lg font-bold tracking-tight uppercase',
                                challenge.locked && 'text-muted-foreground',
                            )}
                        >
                            {challenge.title}
                        </h3>
                    </div>
                    <p className="mt-1 font-mono text-xs tracking-widest text-muted-foreground uppercase">
                        {challenge.difficulty}
                    </p>
                </div>
            </div>

            <div className="flex shrink-0 flex-wrap items-center gap-4 pl-10 sm:pl-0">
                {challenge.stars > 0 && <StarRating value={challenge.stars} />}

                {challenge.bestScore > 0 && (
                    <span
                        className={cn(
                            PILL,
                            'border-border text-muted-foreground',
                        )}
                    >
                        {challenge.bestScore} pts
                    </span>
                )}

                {challenge.locked ? (
                    <>
                        <span
                            className={cn(PILL, 'border-primary text-primary')}
                        >
                            <Lock aria-hidden className="size-3" />
                            {planLabel(challenge.requiredPlan)}
                        </span>
                        <Link
                            href={pricing()}
                            className="font-mono text-xs tracking-widest text-muted-foreground uppercase transition-colors hover:text-primary"
                        >
                            See plans →
                        </Link>
                    </>
                ) : (
                    <span className="font-mono text-xs tracking-widest text-primary uppercase">
                        {signedIn ? 'Fly it →' : 'Sign in to fly →'}
                    </span>
                )}
            </div>
        </div>
    );

    // A locked mission is shown in full but is not a link: the route would turn
    // the pilot away, and a link that 403s reads as a bug rather than a paywall.
    if (challenge.locked) {
        return row;
    }

    return (
        <Link
            href={showChallenge([courseSlug, challenge.slug])}
            className="block outline-none focus-visible:ring-[3px] focus-visible:ring-ring/50"
        >
            {row}
        </Link>
    );
}

/** Layout-stable placeholder matching a single `ChallengeRow`. */
export function ChallengeRowSkeleton() {
    return (
        <div
            aria-hidden="true"
            className="flex items-center justify-between gap-4 border border-border bg-white/[0.02] p-6"
        >
            <div className="flex items-center gap-5">
                <Skeleton className="h-4 w-6" />
                <div className="space-y-2">
                    <Skeleton className="h-5 w-48" />
                    <Skeleton className="h-3 w-20" />
                </div>
            </div>
            <Skeleton className="h-4 w-20 shrink-0" />
        </div>
    );
}
