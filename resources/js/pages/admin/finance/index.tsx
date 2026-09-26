import { Head, Link } from '@inertiajs/react';
import {
    CalendarRange,
    CircleSlash,
    Repeat,
    TrendingUp,
    Users,
    Wallet,
} from 'lucide-react';
import { ColumnChart } from '@/components/admin/column-chart';
import { orderColumns } from '@/components/admin/finance-columns';
import { FinanceTabs } from '@/components/admin/finance-tabs';
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
import { Skeleton } from '@/components/ui/skeleton';
import { formatCount, humanizeStatus } from '@/lib/admin-format';
import type { ColumnDef } from '@/lib/data-table';
import { dashboard, finance } from '@/routes/admin';
import { orders as ordersRoute } from '@/routes/admin/finance';
import type { AdminOrder, RevenueReport } from '@/types/admin';

type FinanceProps = {
    /** `undefined` while the deferred report is in flight. */
    report: RevenueReport | undefined;
    recentOrders: AdminOrder[];
};

type StatusRow = RevenueReport['subscriptions']['byStatus'][number];
type PlanRow = RevenueReport['subscriptions']['byPlan'][number];

const statusColumns: ColumnDef<StatusRow>[] = [
    {
        id: 'status',
        header: 'Status',
        cell: (row) => humanizeStatus(row.status),
    },
    {
        id: 'access',
        header: 'Access',
        cell: (row) =>
            row.entitles ? (
                <Badge variant="secondary">Entitles</Badge>
            ) : (
                <Badge variant="outline">No access</Badge>
            ),
    },
    {
        id: 'count',
        header: 'Subscriptions',
        align: 'end',
        cell: (row) => formatCount(row.count),
    },
];

const planColumns: ColumnDef<PlanRow>[] = [
    { id: 'plan', header: 'Plan', cell: (row) => row.plan },
    {
        id: 'count',
        header: 'Paying or trialing',
        align: 'end',
        cell: (row) => formatCount(row.count),
    },
];

function monthLabel(month: string): string {
    return new Date(`${month}-01T00:00:00`).toLocaleDateString(undefined, {
        month: 'short',
        year: '2-digit',
    });
}

export default function FinanceIndex({ report, recentOrders }: FinanceProps) {
    const loading = report === undefined;

    return (
        <>
            <Head title="Finance" />

            <div className="space-y-6 p-4">
                <PageHeader
                    title="Finance"
                    description="Revenue and subscribers as Creem has reported them. Read-only: charges and refunds happen in Creem."
                />

                <FinanceTabs />

                <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
                    <StatCard
                        label="Net revenue this month"
                        icon={Wallet}
                        loading={loading}
                        value={report?.totals.thisMonth.net}
                        description={
                            report
                                ? `${formatCount(report.totals.thisMonth.orders)} orders · ${report.totals.thisMonth.refunded} refunded`
                                : undefined
                        }
                    />
                    <StatCard
                        label="Net revenue, last 30 days"
                        icon={CalendarRange}
                        loading={loading}
                        value={report?.totals.last30Days.net}
                        description={
                            report
                                ? `${formatCount(report.totals.last30Days.orders)} orders · ${report.totals.last30Days.refunded} refunded`
                                : undefined
                        }
                    />
                    <StatCard
                        label="Net revenue, all time"
                        icon={TrendingUp}
                        loading={loading}
                        value={report?.totals.allTime.net}
                        description={
                            report
                                ? `${formatCount(report.totals.allTime.orders)} orders in ${report.currency}${report.otherCurrencyOrders > 0 ? ` · ${formatCount(report.otherCurrencyOrders)} in other currencies, not summed` : ''}`
                                : undefined
                        }
                    />
                    <StatCard
                        label="Monthly recurring revenue"
                        icon={Repeat}
                        loading={loading}
                        value={report?.subscriptions.mrr}
                        description="Estimated at list price; trials excluded"
                    />
                    <StatCard
                        label="Subscribers with access"
                        icon={Users}
                        loading={loading}
                        value={formatCount(report?.subscriptions.entitled)}
                        description="Active, trialing, past due or winding down"
                    />
                    <StatCard
                        label="Cancelled, last 30 days"
                        icon={CircleSlash}
                        loading={loading}
                        value={formatCount(
                            report?.subscriptions.cancelledLast30Days,
                        )}
                    />
                </div>

                <Card>
                    <CardHeader>
                        <CardTitle className="text-base">
                            Net revenue per month
                            {report ? ` (${report.currency})` : ''}
                        </CardTitle>
                    </CardHeader>
                    <CardContent>
                        {report ? (
                            <ColumnChart
                                title={`Net revenue per month in ${report.currency}, last 12 months`}
                                columns={report.monthly.map((month) => ({
                                    key: month.month,
                                    label: monthLabel(month.month),
                                    value: Math.max(0, month.net),
                                    display: `${month.formatted} · ${formatCount(month.orders)} orders`,
                                }))}
                            />
                        ) : (
                            <Skeleton className="h-44 w-full" />
                        )}
                    </CardContent>
                </Card>

                <div className="grid gap-6 lg:grid-cols-2">
                    <section className="space-y-3">
                        <h2 className="text-base font-semibold">
                            Subscriptions by status
                        </h2>
                        <DataTable
                            caption="Subscriptions by Creem status"
                            columns={statusColumns}
                            rows={report?.subscriptions.byStatus}
                            rowKey={(row) => row.status}
                            skeletonRows={3}
                            empty={
                                <EmptyState className="rounded-none border-0">
                                    <EmptyStateTitle>
                                        No subscriptions yet
                                    </EmptyStateTitle>
                                </EmptyState>
                            }
                        />
                    </section>
                    <section className="space-y-3">
                        <h2 className="text-base font-semibold">
                            Subscribers by plan
                        </h2>
                        <DataTable
                            caption="Subscribers with access, by plan"
                            columns={planColumns}
                            rows={report?.subscriptions.byPlan}
                            rowKey={(row) => row.plan}
                            skeletonRows={3}
                            empty={
                                <EmptyState className="rounded-none border-0">
                                    <EmptyStateTitle>
                                        Nobody subscribed
                                    </EmptyStateTitle>
                                </EmptyState>
                            }
                        />
                    </section>
                </div>

                <section className="space-y-3">
                    <div className="flex items-center justify-between gap-2">
                        <h2 className="text-base font-semibold">
                            Latest orders
                        </h2>
                        <Button variant="outline" size="sm" asChild>
                            <Link href={ordersRoute()}>All orders</Link>
                        </Button>
                    </div>
                    <DataTable
                        caption="The most recent orders"
                        columns={orderColumns()}
                        rows={recentOrders}
                        rowKey={(order) => order.creemId}
                        empty={
                            <EmptyState className="rounded-none border-0">
                                <EmptyStateTitle>No orders yet</EmptyStateTitle>
                                <EmptyStateDescription>
                                    Orders appear when Creem reports a completed
                                    checkout.
                                </EmptyStateDescription>
                            </EmptyState>
                        }
                    />
                </section>
            </div>
        </>
    );
}

FinanceIndex.layout = {
    breadcrumbs: [
        { title: 'Admin', href: dashboard() },
        { title: 'Finance', href: finance() },
    ],
};
