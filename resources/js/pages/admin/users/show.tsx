import { Head, setLayoutProps } from '@inertiajs/react';
import { Camera, CheckCircle2, ListChecks, Target } from 'lucide-react';
import { useUserActions } from '@/components/admin/user-actions';
import { DataTable } from '@/components/data-table';
import { PageHeader } from '@/components/page-header';
import { RowActions } from '@/components/row-actions';
import { StatCard } from '@/components/stat-card';
import { Badge } from '@/components/ui/badge';
import {
    EmptyState,
    EmptyStateDescription,
    EmptyStateTitle,
} from '@/components/ui/empty-state';
import {
    formatCount,
    formatDate,
    formatDateTime,
    formatDuration,
    formatRelative,
} from '@/lib/admin-format';
import type { ColumnDef } from '@/lib/data-table';
import { dashboard } from '@/routes/admin';
import { index, show } from '@/routes/admin/users';
import type { AdminUser, UserFormOptions } from '@/types/admin';

type RecentRun = {
    uuid: string;
    challengeTitle: string;
    courseTitle: string;
    score: number;
    stars: number;
    completed: boolean;
    collisions: number;
    elapsedSeconds: number;
    flownAt: string;
};

type UserShowProps = UserFormOptions & {
    account: AdminUser;
    stats: {
        missionsCompleted: number;
        quizzesPassed: number;
        photos: number;
        lastRunAt: string | null;
    };
    recentRuns: RecentRun[];
};

const runColumns: ColumnDef<RecentRun>[] = [
    {
        id: 'mission',
        header: 'Mission',
        cell: (run) => (
            <div className="min-w-0">
                <span className="block truncate font-medium">
                    {run.challengeTitle}
                </span>
                <span className="block truncate text-xs text-muted-foreground">
                    {run.courseTitle}
                </span>
            </div>
        ),
    },
    {
        id: 'score',
        header: 'Score',
        align: 'end',
        cell: (run) => (
            <span className="tabular-nums">
                {run.score} · {run.stars}★
            </span>
        ),
    },
    {
        id: 'result',
        header: 'Result',
        hideBelow: 'sm',
        cell: (run) =>
            run.completed ? (
                <Badge variant="secondary">Cleared</Badge>
            ) : (
                <Badge variant="outline">Not cleared</Badge>
            ),
    },
    {
        id: 'details',
        header: 'Flight',
        align: 'end',
        hideBelow: 'md',
        cell: (run) =>
            `${formatDuration(run.elapsedSeconds)} · ${run.collisions} collision${run.collisions === 1 ? '' : 's'}`,
    },
    {
        id: 'flownAt',
        header: 'When',
        align: 'end',
        cell: (run) => (
            <time dateTime={run.flownAt} title={formatDateTime(run.flownAt)}>
                {formatRelative(run.flownAt)}
            </time>
        ),
    },
];

function Section({
    title,
    children,
}: {
    title: string;
    children: React.ReactNode;
}) {
    return (
        <section className="space-y-3">
            <h2 className="text-base font-semibold">{title}</h2>
            {children}
        </section>
    );
}

function Empty({ title, description }: { title: string; description: string }) {
    return (
        <EmptyState className="rounded-none border-0">
            <EmptyStateTitle>{title}</EmptyStateTitle>
            <EmptyStateDescription>{description}</EmptyStateDescription>
        </EmptyState>
    );
}

export default function UserShow({
    account,
    stats,
    recentRuns,
    roles,
    plans,
    can,
}: UserShowProps) {
    setLayoutProps({
        breadcrumbs: [
            { title: 'Admin', href: dashboard() },
            { title: 'Users', href: index() },
            { title: account.name, href: show(account.uuid) },
        ],
    });

    const { actionsFor, dialogs } = useUserActions({ roles, plans, can });
    const actions = actionsFor(account, { includeView: false });

    return (
        <>
            <Head title={account.name} />

            <div className="space-y-6 p-4">
                <PageHeader
                    title={account.name}
                    description={`${account.email} · joined ${formatDate(account.createdAt)}`}
                    badge={
                        <div className="flex flex-wrap gap-1">
                            <Badge
                                variant={
                                    account.plan.value === 'starter'
                                        ? 'outline'
                                        : 'secondary'
                                }
                            >
                                {account.plan.label}
                                {account.planOverride && ' · set by hand'}
                            </Badge>
                            {!account.emailVerified && (
                                <Badge variant="destructive">Unverified</Badge>
                            )}
                            {account.roles.map((role) => (
                                <Badge
                                    key={role}
                                    variant={
                                        role === 'admin' ? 'default' : 'outline'
                                    }
                                >
                                    {role}
                                </Badge>
                            ))}
                        </div>
                    }
                    actions={
                        actions.length > 0 && (
                            <RowActions
                                actions={actions}
                                label={`Actions for ${account.name}`}
                                className="border"
                            />
                        )
                    }
                />

                <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                    <StatCard
                        label="Runs flown"
                        icon={Target}
                        value={formatCount(account.runs)}
                        description={
                            stats.lastRunAt
                                ? `Last flew ${formatRelative(stats.lastRunAt)}`
                                : 'Has not flown yet'
                        }
                    />
                    <StatCard
                        label="Missions cleared"
                        icon={CheckCircle2}
                        value={formatCount(stats.missionsCompleted)}
                    />
                    <StatCard
                        label="Quizzes passed"
                        icon={ListChecks}
                        value={formatCount(stats.quizzesPassed)}
                    />
                    <StatCard
                        label="Photos"
                        icon={Camera}
                        value={formatCount(stats.photos)}
                    />
                </div>

                <Section title="Recent runs">
                    <DataTable
                        caption={`The last runs ${account.name} flew`}
                        columns={runColumns}
                        rows={recentRuns}
                        rowKey={(run) => run.uuid}
                        empty={
                            <Empty
                                title="No runs yet"
                                description="Runs appear here once this pilot flies a mission."
                            />
                        }
                    />
                </Section>
            </div>

            {dialogs}
        </>
    );
}
