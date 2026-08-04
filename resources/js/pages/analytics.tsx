import { Head, router } from '@inertiajs/react';
import { Crosshair, Gauge, Timer, Trophy } from 'lucide-react';
import { AttemptCurve } from '@/components/analytics/attempt-curve';
import { CohortMeter } from '@/components/analytics/cohort-meter';
import { WeakSpotList } from '@/components/analytics/weak-spot-list';
import { DataBoundary } from '@/components/data-boundary';
import { PageHeader } from '@/components/page-header';
import { StatCard } from '@/components/stat-card';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
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
import { Skeleton } from '@/components/ui/skeleton';
import { analytics } from '@/routes';
import type {
    CurvePoint,
    FlightSummary,
    FlownMission,
    MissionCohort,
    WeakSpot,
} from '@/types/analytics';

type SelectedMission = {
    slug: string;
    title: string;
    /** The mission's own ceiling — the chart's y-axis, not a derived max. */
    maxScore: number;
};

type AnalyticsProps = {
    summary: FlightSummary;
    missions: FlownMission[];
    selected: SelectedMission | null;
    /** `undefined` while deferred; `[]` means the mission has no runs. */
    curve: CurvePoint[] | undefined;
    cohort: MissionCohort | null | undefined;
    weakSpots: WeakSpot[];
};

/** Seconds as the coarsest unit that still reads as a duration. */
function formatFlightTime(seconds: number): string {
    if (seconds < 60) {
        return `${Math.round(seconds)}s`;
    }

    if (seconds < 3600) {
        return `${Math.round(seconds / 60)}m`;
    }

    return `${(seconds / 3600).toFixed(1)}h`;
}

export default function Analytics({
    summary,
    missions,
    selected,
    curve,
    cohort,
    weakSpots,
}: AnalyticsProps) {
    function selectMission(slug: string) {
        router.get(
            analytics.url({ query: { mission: slug } }),
            {},
            { preserveScroll: true, replace: true },
        );
    }

    if (summary.runs === 0) {
        return (
            <>
                <Head title="Analytics" />

                <div className="space-y-6 p-4">
                    <PageHeader
                        title="Analytics"
                        description="Every run you fly is recorded here."
                    />

                    <EmptyState>
                        <EmptyStateIcon>
                            <Gauge />
                        </EmptyStateIcon>
                        <EmptyStateTitle>Nothing flown yet</EmptyStateTitle>
                        <EmptyStateDescription>
                            Fly a mission and this page fills in: how your score
                            moved attempt by attempt, where you are losing
                            objectives, and how you compare with every other
                            pilot.
                        </EmptyStateDescription>
                    </EmptyState>
                </div>
            </>
        );
    }

    return (
        <>
            <Head title="Analytics" />

            <div className="space-y-6 p-4">
                <PageHeader
                    title="Analytics"
                    description={`Across ${summary.runs} recorded run${summary.runs === 1 ? '' : 's'} on ${summary.missionsFlown} mission${summary.missionsFlown === 1 ? '' : 's'}.`}
                />

                <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    <StatCard
                        label="Missions cleared"
                        value={`${summary.missionsCleared} / ${summary.missionsFlown}`}
                        icon={Trophy}
                        description={`${Math.round(summary.clearRate * 100)}% of what you have flown`}
                    />
                    <StatCard
                        label="Attempts to clear"
                        value={summary.meanAttemptsToClear?.toFixed(1) ?? '—'}
                        icon={Crosshair}
                        description={
                            summary.meanAttemptsToClear === null
                                ? 'Clear a mission to start the average'
                                : 'Runs taken, averaged over missions cleared'
                        }
                    />
                    <StatCard
                        label="Clean runs"
                        value={`${Math.round(summary.cleanRunRate * 100)}%`}
                        icon={Gauge}
                        description="Flights finished without a collision"
                    />
                    <StatCard
                        label="Time in the air"
                        value={formatFlightTime(summary.flightSeconds)}
                        icon={Timer}
                        description={`Best single run: ${summary.bestScore}`}
                    />
                </div>

                <Card>
                    <CardHeader className="flex-row items-center justify-between gap-4 space-y-0">
                        <CardTitle className="text-base">
                            Score by attempt
                        </CardTitle>

                        {missions.length > 0 && (
                            <Select
                                onValueChange={selectMission}
                                value={selected?.slug ?? undefined}
                            >
                                <SelectTrigger
                                    aria-label="Choose which mission to chart"
                                    className="w-60"
                                >
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    {missions.map((mission) => (
                                        <SelectItem
                                            key={`${mission.courseSlug}/${mission.challengeSlug}`}
                                            value={mission.challengeSlug}
                                        >
                                            {mission.challengeTitle} ·{' '}
                                            {mission.runs} run
                                            {mission.runs === 1 ? '' : 's'}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        )}
                    </CardHeader>
                    <CardContent>
                        <DataBoundary
                            data={curve}
                            skeleton={<Skeleton className="h-64 w-full" />}
                            empty={
                                <p className="py-8 text-center text-sm text-muted-foreground">
                                    No runs recorded on this mission yet.
                                </p>
                            }
                        >
                            {(points) => (
                                <AttemptCurve
                                    maxScore={selected?.maxScore ?? 100}
                                    missionTitle={
                                        selected?.title ?? 'this mission'
                                    }
                                    points={points}
                                />
                            )}
                        </DataBoundary>
                    </CardContent>
                </Card>

                <div className="grid gap-4 lg:grid-cols-2">
                    <Card>
                        <CardHeader className="space-y-0">
                            <CardTitle className="text-base">
                                How you compare
                            </CardTitle>
                        </CardHeader>
                        <CardContent>
                            <DataBoundary
                                data={cohort}
                                skeleton={<Skeleton className="h-28 w-full" />}
                                empty={
                                    <p className="text-sm text-muted-foreground">
                                        Fly this mission to join its ranking.
                                    </p>
                                }
                            >
                                {(missionCohort) => (
                                    <CohortMeter
                                        cohort={missionCohort}
                                        missionTitle={
                                            selected?.title ?? 'this mission'
                                        }
                                    />
                                )}
                            </DataBoundary>
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader className="space-y-0">
                            <CardTitle className="text-base">
                                Where to practise
                            </CardTitle>
                        </CardHeader>
                        <CardContent>
                            {weakSpots.length > 0 ? (
                                <WeakSpotList weakSpots={weakSpots} />
                            ) : (
                                <p className="text-sm text-muted-foreground">
                                    Nothing has taken you three attempts yet.
                                    Come back once a mission has put up a fight.
                                </p>
                            )}
                        </CardContent>
                    </Card>
                </div>
            </div>
        </>
    );
}

Analytics.layout = {
    breadcrumbs: [{ title: 'Analytics', href: analytics() }],
};
