import { Head, router } from '@inertiajs/react';
import { Medal, Star, Trophy } from 'lucide-react';
import { PageHeader } from '@/components/page-header';
import { Avatar, AvatarFallback } from '@/components/ui/avatar';
import { Badge } from '@/components/ui/badge';
import { Card, CardContent } from '@/components/ui/card';
import {
    EmptyState,
    EmptyStateDescription,
    EmptyStateIcon,
    EmptyStateTitle,
} from '@/components/ui/empty-state';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { useInitials } from '@/hooks/use-initials';
import { cn } from '@/lib/utils';
import { leaderboard } from '@/routes';
import type { LeaderboardStanding } from '@/types/simulator';

type LeaderboardProps = {
    courses: { title: string; slug: string }[];
    courseSlug: string | null;
    courseTitle: string | null;
    standings: LeaderboardStanding[];
    you: LeaderboardStanding | null;
    pilotCount: number;
    topPilots: number;
};

/** Sentinel for the unscoped board; `Select` cannot hold an empty value. */
const ALL_COURSES = 'all';

/** Podium tints for the first three places; everyone else stays neutral. */
const MEDAL_CLASSES: Record<number, string> = {
    1: 'text-amber-500',
    2: 'text-zinc-400',
    3: 'text-orange-700 dark:text-orange-600',
};

function RankBadge({ rank }: { rank: number }) {
    const medal = MEDAL_CLASSES[rank];

    return (
        <span className="flex w-10 items-center gap-1 font-mono text-sm tabular-nums">
            {medal ? (
                <Medal aria-hidden="true" className={cn('size-4', medal)} />
            ) : (
                <span aria-hidden="true" className="size-4" />
            )}
            {rank}
        </span>
    );
}

function PodiumCard({ standing }: { standing: LeaderboardStanding }) {
    const getInitials = useInitials();
    const medal = MEDAL_CLASSES[standing.rank];

    return (
        <Card
            className={cn(
                'text-center',
                standing.rank === 1 && 'border-amber-500/40 sm:-mt-4',
                standing.isYou && 'ring-2 ring-primary/40',
            )}
        >
            <CardContent className="flex flex-col items-center gap-2 pt-6">
                <Medal aria-hidden="true" className={cn('size-6', medal)} />
                <Avatar className="size-12">
                    <AvatarFallback className="bg-muted text-base font-medium">
                        {getInitials(standing.name)}
                    </AvatarFallback>
                </Avatar>
                <div className="flex items-center gap-2">
                    <span className="font-medium">{standing.name}</span>
                    {standing.isYou && <Badge variant="secondary">You</Badge>}
                </div>
                <div className="text-2xl font-semibold tabular-nums">
                    {standing.points.toLocaleString()}
                    <span className="ml-1 text-sm font-normal text-muted-foreground">
                        pts
                    </span>
                </div>
                <p className="text-xs text-muted-foreground">
                    {standing.completed} complete · {standing.stars} stars
                </p>
            </CardContent>
        </Card>
    );
}

function StandingRow({ standing }: { standing: LeaderboardStanding }) {
    const getInitials = useInitials();

    return (
        <tr
            className={cn(
                'border-t',
                standing.isYou && 'bg-primary/5 font-medium',
            )}
        >
            <td className="py-2 pr-2 pl-3">
                <RankBadge rank={standing.rank} />
            </td>
            <td className="py-2 pr-2">
                <div className="flex items-center gap-2">
                    <Avatar className="size-7">
                        <AvatarFallback className="bg-muted text-xs">
                            {getInitials(standing.name)}
                        </AvatarFallback>
                    </Avatar>
                    <span className="truncate">{standing.name}</span>
                    {standing.isYou && <Badge variant="secondary">You</Badge>}
                </div>
            </td>
            <td className="hidden py-2 pr-2 text-right tabular-nums sm:table-cell">
                {standing.completed}
            </td>
            <td className="py-2 pr-2 text-right tabular-nums">
                <span className="inline-flex items-center gap-1">
                    <Star
                        aria-hidden="true"
                        className="size-3.5 text-muted-foreground"
                    />
                    {standing.stars}
                </span>
            </td>
            <td className="py-2 pr-3 text-right font-mono tabular-nums">
                {standing.points.toLocaleString()}
            </td>
        </tr>
    );
}

export default function Leaderboard({
    courses,
    courseSlug,
    courseTitle,
    standings,
    you,
    pilotCount,
    topPilots,
}: LeaderboardProps) {
    // A tie at the cut-off can push you off the board while your rank still
    // reads inside it, so go by whether your row actually made the list.
    const youAreListed = standings.some((standing) => standing.isYou);
    const podium = standings.length >= 3 ? standings.slice(0, 3) : [];

    function filterByCourse(value: string) {
        router.get(
            leaderboard.url(
                value === ALL_COURSES ? {} : { query: { course: value } },
            ),
            {},
            { preserveScroll: true, replace: true },
        );
    }

    return (
        <>
            <Head title="Leaderboard" />

            <div className="space-y-6 p-4">
                <PageHeader
                    title="Leaderboard"
                    description={
                        pilotCount > 0
                            ? `${pilotCount} pilot${pilotCount === 1 ? '' : 's'} ranked by mission points${courseTitle ? ` in ${courseTitle}` : ' across every course'}. Beat your best score on a mission to climb.`
                            : 'Fly a mission to put yourself on the board.'
                    }
                    actions={
                        courses.length > 0 && (
                            <Select
                                value={courseSlug ?? ALL_COURSES}
                                onValueChange={filterByCourse}
                            >
                                <SelectTrigger
                                    className="w-52"
                                    aria-label="Filter the leaderboard by course"
                                >
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value={ALL_COURSES}>
                                        All courses
                                    </SelectItem>
                                    {courses.map((course) => (
                                        <SelectItem
                                            key={course.slug}
                                            value={course.slug}
                                        >
                                            {course.title}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        )
                    }
                />

                {standings.length > 0 ? (
                    <>
                        {podium.length === 3 && (
                            <div className="grid gap-4 sm:grid-cols-3 sm:items-end">
                                {/* Silver, gold, bronze — the winner sits centre stage on wide screens. */}
                                <div className="sm:order-2">
                                    <PodiumCard standing={podium[0]} />
                                </div>
                                <div className="sm:order-1">
                                    <PodiumCard standing={podium[1]} />
                                </div>
                                <div className="sm:order-3">
                                    <PodiumCard standing={podium[2]} />
                                </div>
                            </div>
                        )}

                        <div className="overflow-x-auto rounded-xl border bg-card">
                            <table className="w-full text-sm">
                                <caption className="sr-only">
                                    Pilots ranked by total mission points
                                    {courseTitle ? ` in ${courseTitle}` : ''}
                                </caption>
                                <thead className="text-xs text-muted-foreground">
                                    <tr>
                                        <th
                                            scope="col"
                                            className="py-2 pr-2 pl-3 text-left font-medium"
                                        >
                                            Rank
                                        </th>
                                        <th
                                            scope="col"
                                            className="py-2 pr-2 text-left font-medium"
                                        >
                                            Pilot
                                        </th>
                                        <th
                                            scope="col"
                                            className="hidden py-2 pr-2 text-right font-medium sm:table-cell"
                                        >
                                            Complete
                                        </th>
                                        <th
                                            scope="col"
                                            className="py-2 pr-2 text-right font-medium"
                                        >
                                            Stars
                                        </th>
                                        <th
                                            scope="col"
                                            className="py-2 pr-3 text-right font-medium"
                                        >
                                            Points
                                        </th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {standings.map((standing, index) => (
                                        <StandingRow
                                            key={`${standing.rank}-${index}`}
                                            standing={standing}
                                        />
                                    ))}

                                    {you && !youAreListed && (
                                        <>
                                            <tr className="border-t">
                                                <td
                                                    colSpan={5}
                                                    className="py-1 text-center text-xs text-muted-foreground"
                                                >
                                                    ···
                                                </td>
                                            </tr>
                                            <StandingRow standing={you} />
                                        </>
                                    )}
                                </tbody>
                            </table>
                        </div>

                        {standings.length >= topPilots && (
                            <p className="text-center text-xs text-muted-foreground">
                                Showing the top {topPilots} of {pilotCount}{' '}
                                pilots.
                            </p>
                        )}
                    </>
                ) : (
                    <EmptyState>
                        <EmptyStateIcon>
                            <Trophy />
                        </EmptyStateIcon>
                        <EmptyStateTitle>Nobody has flown yet</EmptyStateTitle>
                        <EmptyStateDescription>
                            {courseTitle
                                ? `No pilot has scored on ${courseTitle} so far. Fly one of its missions and you take first place.`
                                : 'Complete a mission to claim the top spot.'}
                        </EmptyStateDescription>
                    </EmptyState>
                )}
            </div>
        </>
    );
}

Leaderboard.layout = {
    breadcrumbs: [{ title: 'Leaderboard', href: leaderboard() }],
};
