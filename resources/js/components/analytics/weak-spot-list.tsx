import { Link } from '@inertiajs/react';
import { ArrowRight, CheckCircle2, CircleDashed } from 'lucide-react';
import { Badge } from '@/components/ui/badge';
import { show as showChallenge } from '@/routes/challenges';
import type { WeakSpot } from '@/types/analytics';

type WeakSpotListProps = {
    weakSpots: WeakSpot[];
};

/**
 * The missions costing this pilot the most runs.
 *
 * A ranked list rather than a bar chart: the ordering is the message, the
 * rows carry four different measures that share no scale, and each one has to
 * be a link into the mission it names. The score bar is the only magnitude
 * worth drawing, and it is drawn against the mission's own ceiling.
 *
 * Cleared and uncleared are marked with an icon and a word, never colour
 * alone — the state is the reason a row is on the list at all.
 */
export function WeakSpotList({ weakSpots }: WeakSpotListProps) {
    return (
        <ul className="divide-y">
            {weakSpots.map((spot) => {
                const ceiling = spot.maxScore > 0 ? spot.maxScore : 100;
                const share = Math.min(
                    100,
                    Math.round((spot.bestScore / ceiling) * 100),
                );

                return (
                    <li
                        className="py-3 first:pt-0 last:pb-0"
                        key={`${spot.courseSlug}/${spot.challengeSlug}`}
                    >
                        <div className="flex items-start justify-between gap-3">
                            <div className="min-w-0">
                                <Link
                                    className="font-medium hover:underline"
                                    href={showChallenge([
                                        spot.courseSlug,
                                        spot.challengeSlug,
                                    ])}
                                >
                                    {spot.challengeTitle}
                                </Link>
                                <p className="text-xs text-muted-foreground">
                                    {spot.courseTitle}
                                </p>
                            </div>

                            <Badge
                                className="shrink-0 gap-1"
                                variant={spot.cleared ? 'secondary' : 'outline'}
                            >
                                {spot.cleared ? (
                                    <CheckCircle2
                                        aria-hidden="true"
                                        className="size-3"
                                    />
                                ) : (
                                    <CircleDashed
                                        aria-hidden="true"
                                        className="size-3"
                                    />
                                )}
                                {spot.cleared ? 'Cleared' : 'Not cleared'}
                            </Badge>
                        </div>

                        <div className="mt-2 h-1.5 overflow-hidden rounded-full bg-muted">
                            <div
                                className="h-full rounded-full bg-chart-3 dark:bg-chart-1"
                                style={{ width: `${share}%` }}
                            />
                        </div>

                        <p className="mt-1.5 text-xs text-muted-foreground">
                            {spot.runs} run{spot.runs === 1 ? '' : 's'} · best{' '}
                            <span className="tabular-nums">
                                {spot.bestScore}
                            </span>
                            /{ceiling} ·{' '}
                            <span className="tabular-nums">
                                {spot.meanCollisions.toFixed(1)}
                            </span>{' '}
                            collisions per run
                        </p>
                    </li>
                );
            })}

            {weakSpots.length > 0 && (
                <li className="pt-3 text-xs text-muted-foreground">
                    <span className="inline-flex items-center gap-1">
                        Missions you have flown at least three times, the ones
                        you have not cleared first
                        <ArrowRight aria-hidden="true" className="size-3" />
                    </span>
                </li>
            )}
        </ul>
    );
}
