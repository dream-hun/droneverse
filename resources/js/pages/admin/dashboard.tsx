import { Head, Link } from '@inertiajs/react';
import { Activity, Radio, Target, UserPlus, Users } from 'lucide-react';
import { ActivityList } from '@/components/admin/activity-list';
import { ColumnChart } from '@/components/admin/column-chart';
import { DataTable } from '@/components/data-table';
import { PageHeader } from '@/components/page-header';
import { StatCard } from '@/components/stat-card';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import {
    EmptyState,
    EmptyStateDescription,
    EmptyStateTitle,
} from '@/components/ui/empty-state';
import { Skeleton } from '@/components/ui/skeleton';
import { formatCount, formatPercent } from '@/lib/admin-format';
import type { ColumnDef } from '@/lib/data-table';
import { activity as activityRoute, dashboard } from '@/routes/admin';
import type { ActivityItem, AdminOverview } from '@/types/admin';

type DashboardProps = {
    /** `undefined` while the deferred aggregates are in flight. */
    overview: AdminOverview | undefined;
    activity: ActivityItem[];
};

type BusyMission = AdminOverview['busiestMissions'][number];

const busiestColumns: ColumnDef<BusyMission>[] = [
    {
        id: 'mission',
        header: 'Mission',
        cell: (row) => (
            <div className="min-w-0">
                <span className="block truncate font-medium">
                    {row.challengeTitle}
                </span>
                <span className="block truncate text-xs text-muted-foreground">
                    {row.courseTitle}
                </span>
            </div>
        ),
    },
    {
        id: 'runs',
        header: 'Runs',
        align: 'end',
        cell: (row) => formatCount(row.runs),
    },
    {
        id: 'pilots',
        header: 'Pilots',
        align: 'end',
        cell: (row) => formatCount(row.pilots),
    },
    {
        id: 'clearRate',
        header: 'Cleared',
        align: 'end',
        cell: (row) => formatPercent(row.clearRate),
    },
];

function dayLabel(date: string): string {
    return new Date(`${date}T00:00:00`).toLocaleDateString(undefined, {
        day: 'numeric',
        month: 'short',
    });
}

function ChartCard({
    title,
    columns,
}: {
    title: string;
    columns: { key: string; label: string; value: number }[] | undefined;
}) {
    return (
        <Card>
            <CardHeader>
                <CardTitle className="text-base">{title}</CardTitle>
            </CardHeader>
            <CardContent>
                {columns ? (
                    <ColumnChart title={title} columns={columns} />
                ) : (
                    <Skeleton className="h-44 w-full" />
                )}
            </CardContent>
        </Card>
    );
}

export default function AdminDashboard({ overview, activity }: DashboardProps) {
    const loading = overview === undefined;

    return (
        <>
            <Head title="Admin" />

            <div className="space-y-6 p-4">
                <PageHeader
                    title="Admin overview"
                    description="Who is here, what they are flying, and what just happened."
                />

                <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                    <StatCard
                        label="Pilots"
                        icon={Users}
                        loading={loading}
                        value={formatCount(overview?.pilots.total)}
                        description={
                            overview
                                ? `${formatCount(overview.pilots.unverified)} unverified · ${formatCount(overview.pilots.staff)} staff`
                                : undefined
                        }
                    />
                    <StatCard
                        label="New this week"
                        icon={UserPlus}
                        loading={loading}
                        value={formatCount(overview?.pilots.newThisWeek)}
                        description="Accounts opened in the last 7 days"
                    />
                    <StatCard
                        label="Runs, last 24 hours"
                        icon={Target}
                        loading={loading}
                        value={formatCount(overview?.flying.runs)}
                        description={
                            overview
                                ? `${formatCount(overview.flying.activePilots)} pilots · ${formatPercent(overview.flying.clearRate)} cleared`
                                : undefined
                        }
                    />
                    <StatCard
                        label="Online now"
                        icon={Radio}
                        loading={loading}
                        value={formatCount(overview?.online)}
                        description={
                            overview && overview.online === null
                                ? 'Needs database sessions to count'
                                : 'Signed-in pilots, last 5 minutes'
                        }
                    />
                </div>

                <div className="grid gap-4 lg:grid-cols-2">
                    <ChartCard
                        title="Sign-ups per day, last 14 days"
                        columns={overview?.daily.map((day) => ({
                            key: day.date,
                            label: dayLabel(day.date),
                            value: day.signups,
                        }))}
                    />
                    <ChartCard
                        title="Mission runs per day, last 14 days"
                        columns={overview?.daily.map((day) => ({
                            key: day.date,
                            label: dayLabel(day.date),
                            value: day.runs,
                        }))}
                    />
                </div>

                <div className="grid gap-6 xl:grid-cols-5">
                    <section className="space-y-3 xl:col-span-2">
                        <h2 className="text-base font-semibold">
                            Busiest missions this week
                        </h2>
                        <DataTable
                            caption="The five missions with the most runs in the last seven days"
                            columns={busiestColumns}
                            rows={overview?.busiestMissions}
                            rowKey={(row) =>
                                `${row.courseSlug}/${row.challengeSlug}`
                            }
                            empty={
                                <EmptyState className="rounded-none border-0">
                                    <EmptyStateTitle>
                                        Nobody flew this week
                                    </EmptyStateTitle>
                                    <EmptyStateDescription>
                                        Missions appear here once pilots start
                                        submitting runs.
                                    </EmptyStateDescription>
                                </EmptyState>
                            }
                        />
                    </section>

                    <section className="space-y-3 xl:col-span-3">
                        <div className="flex items-center justify-between gap-2">
                            <h2 className="text-base font-semibold">
                                Recent activity
                            </h2>
                            <Button variant="outline" size="sm" asChild>
                                <Link href={activityRoute()}>
                                    <Activity />
                                    All activity
                                </Link>
                            </Button>
                        </div>
                        {activity.length > 0 ? (
                            <ActivityList items={activity} />
                        ) : (
                            <EmptyState>
                                <EmptyStateTitle>Nothing yet</EmptyStateTitle>
                                <EmptyStateDescription>
                                    Sign-ups, runs and payments show up here as
                                    they happen.
                                </EmptyStateDescription>
                            </EmptyState>
                        )}
                    </section>
                </div>
            </div>
        </>
    );
}

AdminDashboard.layout = {
    breadcrumbs: [{ title: 'Admin', href: dashboard() }],
};
