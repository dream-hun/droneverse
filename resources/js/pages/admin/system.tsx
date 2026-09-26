import { Head, router, usePoll } from '@inertiajs/react';
import {
    CircleAlert,
    CircleCheck,
    Cpu,
    FileText,
    HardDrive,
    Hourglass,
    Layers,
    RotateCcw,
    Trash2,
    Users,
} from 'lucide-react';
import { useState } from 'react';
import { ConfirmDialog } from '@/components/confirm-dialog';
import { DataTable } from '@/components/data-table';
import { PageHeader } from '@/components/page-header';
import { StatCard } from '@/components/stat-card';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import {
    EmptyState,
    EmptyStateDescription,
    EmptyStateTitle,
} from '@/components/ui/empty-state';
import {
    MISSING,
    formatBytes,
    formatCount,
    formatDateTime,
    formatDuration,
    formatRelative,
} from '@/lib/admin-format';
import type { ColumnDef } from '@/lib/data-table';
import { visitAsPromise } from '@/lib/inertia-promise';
import type { RowAction } from '@/lib/row-actions';
import { dashboard, system } from '@/routes/admin';
import {
    destroy as forgetJob,
    retry as retryJob,
} from '@/routes/admin/system/failed-jobs';
import type { FailedJob, HealthCheck, SystemReport } from '@/types/admin';

type SystemProps = {
    report: SystemReport;
    /** Only for staff the log viewer's own gate admits. */
    logViewerUrl: string | null;
};

/** How often the checks re-run while the page is open. */
const POLL_MS = 30_000;

const tableColumns: ColumnDef<SystemReport['tables'][number]>[] = [
    {
        id: 'name',
        header: 'Table',
        cell: (table) => <span className="font-mono">{table.name}</span>,
    },
    {
        id: 'rows',
        header: 'Rows',
        align: 'end',
        cell: (table) => formatCount(table.rows),
    },
    {
        id: 'size',
        header: 'Size',
        align: 'end',
        cell: (table) => formatBytes(table.size),
    },
];

function CheckRow({ check }: { check: HealthCheck }) {
    const Icon = check.ok ? CircleCheck : CircleAlert;

    return (
        <li className="flex items-start gap-3 py-3">
            <Icon
                aria-hidden="true"
                className={
                    check.ok
                        ? 'mt-0.5 size-4 shrink-0 text-emerald-600 dark:text-emerald-400'
                        : 'mt-0.5 size-4 shrink-0 text-destructive'
                }
            />
            <div className="min-w-0 flex-1">
                <p className="text-sm font-medium">
                    {check.name}{' '}
                    <span className="font-normal text-muted-foreground">
                        — {check.ok ? 'healthy' : 'needs attention'}
                    </span>
                </p>
                <p className="text-xs break-words text-muted-foreground">
                    {check.detail}
                </p>
            </div>
            {check.latencyMs !== null && (
                <span className="shrink-0 text-xs text-muted-foreground tabular-nums">
                    {check.latencyMs.toFixed(1)} ms
                </span>
            )}
        </li>
    );
}

export default function SystemPage({ report, logViewerUrl }: SystemProps) {
    usePoll(POLL_MS, { only: ['report'] });

    const [forgetting, setForgetting] = useState<FailedJob | null>(null);
    const failing = report.checks.filter((check) => !check.ok).length;
    const { environment, runtime, queue } = report;

    const failedJobColumns: ColumnDef<FailedJob>[] = [
        {
            id: 'job',
            header: 'Job',
            cell: (job) => (
                <div className="max-w-md min-w-0">
                    <span className="block truncate font-medium">
                        {job.job}
                    </span>
                    <span className="block text-xs break-words text-muted-foreground">
                        {job.exception}
                    </span>
                </div>
            ),
        },
        {
            id: 'queue',
            header: 'Queue',
            hideBelow: 'md',
            cell: (job) => (
                <span className="font-mono text-xs">
                    {job.connection}/{job.queue}
                </span>
            ),
        },
        {
            id: 'failedAt',
            header: 'Failed',
            align: 'end',
            cell: (job) => (
                <time
                    dateTime={job.failedAt ?? undefined}
                    title={formatDateTime(job.failedAt)}
                >
                    {formatRelative(job.failedAt)}
                </time>
            ),
        },
    ];

    function jobActions(job: FailedJob): RowAction[] {
        return [
            {
                label: 'Retry',
                icon: <RotateCcw />,
                onSelect: () =>
                    router.post(
                        retryJob.url(job.id),
                        {},
                        { preserveScroll: true },
                    ),
            },
            {
                label: 'Discard',
                icon: <Trash2 />,
                variant: 'destructive',
                group: 'danger',
                onSelect: () => setForgetting(job),
            },
        ];
    }

    return (
        <>
            <Head title="System" />

            <div className="space-y-6 p-4">
                <PageHeader
                    title="System"
                    description={`Live checks, re-run every ${POLL_MS / 1000} seconds while this page is open.`}
                    badge={
                        failing === 0 ? (
                            <Badge variant="secondary">
                                All checks passing
                            </Badge>
                        ) : (
                            <Badge variant="destructive">
                                {failing} check{failing === 1 ? '' : 's'}{' '}
                                failing
                            </Badge>
                        )
                    }
                    actions={
                        logViewerUrl && (
                            <Button variant="outline" asChild>
                                {/* The log viewer is its own app, not an Inertia page. */}
                                <a href={logViewerUrl}>
                                    <FileText />
                                    Open logs
                                </a>
                            </Button>
                        )
                    }
                />

                <div className="grid gap-6 lg:grid-cols-2">
                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base">
                                Health checks
                            </CardTitle>
                        </CardHeader>
                        <CardContent>
                            <ul className="-my-3 divide-y">
                                {report.checks.map((check) => (
                                    <CheckRow key={check.name} check={check} />
                                ))}
                            </ul>
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base">
                                Environment
                            </CardTitle>
                        </CardHeader>
                        <CardContent>
                            <dl className="grid grid-cols-[auto_1fr] gap-x-6 gap-y-2 text-sm">
                                <dt className="text-muted-foreground">
                                    Environment
                                </dt>
                                <dd className="flex flex-wrap items-center gap-1">
                                    {environment.environment}
                                    {environment.debug && (
                                        <Badge variant="destructive">
                                            debug on
                                        </Badge>
                                    )}
                                    {environment.maintenance && (
                                        <Badge variant="destructive">
                                            maintenance
                                        </Badge>
                                    )}
                                </dd>
                                <dt className="text-muted-foreground">
                                    PHP / Laravel
                                </dt>
                                <dd>
                                    {environment.php} / {environment.laravel}
                                </dd>
                                <dt className="text-muted-foreground">
                                    Timezone
                                </dt>
                                <dd>{environment.timezone}</dd>
                                {Object.entries(environment.drivers).map(
                                    ([name, driver]) => (
                                        <div key={name} className="contents">
                                            <dt className="text-muted-foreground">
                                                {name}
                                            </dt>
                                            <dd className="font-mono text-xs leading-5">
                                                {driver || MISSING}
                                            </dd>
                                        </div>
                                    ),
                                )}
                            </dl>
                        </CardContent>
                    </Card>
                </div>

                <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                    <StatCard
                        label="Queue backlog"
                        icon={Layers}
                        value={formatCount(queue.pending)}
                        description={
                            queue.pending === null
                                ? `Not countable on the ${queue.connection} driver`
                                : queue.oldestPendingSeconds !== null
                                  ? `Oldest waiting ${formatDuration(queue.oldestPendingSeconds)}`
                                  : `Nothing waiting on ${queue.connection}`
                        }
                    />
                    <StatCard
                        label="Failed jobs"
                        icon={Hourglass}
                        value={formatCount(queue.failed)}
                        description="Kept until retried or discarded"
                    />
                    <StatCard
                        label="Active sessions"
                        icon={Users}
                        value={formatCount(report.sessions.last5Minutes)}
                        description={
                            report.sessions.last60Minutes === null
                                ? 'Needs database sessions to count'
                                : `Last 5 minutes · ${formatCount(report.sessions.last60Minutes)} in the last hour`
                        }
                    />
                    <StatCard
                        label="Disk free"
                        icon={HardDrive}
                        value={formatBytes(runtime.diskFree)}
                        description={
                            runtime.diskTotal === null
                                ? 'Not reported by this host'
                                : `of ${formatBytes(runtime.diskTotal)}`
                        }
                    />
                    <StatCard
                        label="Peak memory, this request"
                        icon={Cpu}
                        value={formatBytes(runtime.memoryPeak)}
                        description={`Limit ${runtime.memoryLimit} · OPcache ${runtime.opcache ? 'on' : 'off'}`}
                    />
                    <StatCard
                        label="Load average"
                        icon={Cpu}
                        value={
                            runtime.loadAverage
                                ? runtime.loadAverage[0].toFixed(2)
                                : MISSING
                        }
                        description={
                            runtime.loadAverage
                                ? `1 / 5 / 15 min: ${runtime.loadAverage.map((load) => load.toFixed(2)).join(' / ')}`
                                : 'Not reported by this host'
                        }
                    />
                </div>

                <section className="space-y-3">
                    <h2 className="text-base font-semibold">Failed jobs</h2>
                    <DataTable
                        caption="The most recent failed queue jobs"
                        columns={failedJobColumns}
                        rows={report.failedJobs}
                        rowKey={(job) => job.id}
                        actions={jobActions}
                        actionsLabel={(job) => `Actions for ${job.job}`}
                        empty={
                            <EmptyState className="rounded-none border-0">
                                <EmptyStateTitle>
                                    No failed jobs
                                </EmptyStateTitle>
                                <EmptyStateDescription>
                                    Jobs that exhaust their retries are kept
                                    here until someone retries or discards them.
                                </EmptyStateDescription>
                            </EmptyState>
                        }
                    />
                </section>

                <section className="space-y-3">
                    <h2 className="text-base font-semibold">Data</h2>
                    <DataTable
                        caption="Row counts and sizes of the tables that grow"
                        columns={tableColumns}
                        rows={report.tables}
                        rowKey={(table) => table.name}
                    />
                </section>
            </div>

            <ConfirmDialog
                open={forgetting !== null}
                onOpenChange={(open) => !open && setForgetting(null)}
                destructive
                title="Discard this failed job?"
                description={`${forgetting?.job ?? 'The job'} will not run again. Retry it instead if its work still needs doing.`}
                confirmLabel="Discard"
                pendingLabel="Discarding…"
                onConfirm={() =>
                    visitAsPromise(
                        (options) =>
                            router.delete(
                                forgetJob.url(forgetting?.id ?? ''),
                                options,
                            ),
                        {},
                        'The job was not discarded',
                    )
                }
            />
        </>
    );
}

SystemPage.layout = {
    breadcrumbs: [
        { title: 'Admin', href: dashboard() },
        { title: 'System', href: system() },
    ],
};
