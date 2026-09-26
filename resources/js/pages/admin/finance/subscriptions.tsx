import { Head } from '@inertiajs/react';
import { FilterSelect } from '@/components/admin/filter-select';
import { subscriptionColumns } from '@/components/admin/finance-columns';
import { FinanceTabs } from '@/components/admin/finance-tabs';
import { ListPagination } from '@/components/admin/list-pagination';
import { SearchField } from '@/components/admin/search-field';
import { DataTable } from '@/components/data-table';
import { PageHeader } from '@/components/page-header';
import {
    EmptyState,
    EmptyStateDescription,
    EmptyStateTitle,
} from '@/components/ui/empty-state';
import { useListFilters } from '@/hooks/use-list-filters';
import { humanizeStatus } from '@/lib/admin-format';
import { dashboard, finance } from '@/routes/admin';
import { subscriptions as subscriptionsRoute } from '@/routes/admin/finance';
import type { AdminSubscription, Pagination } from '@/types/admin';

type SubscriptionsProps = {
    subscriptions: AdminSubscription[];
    pagination: Pagination;
    filters: { q: string; status: string | null };
    /** Mirrors App\Enums\SubscriptionStatus. */
    statuses: { value: string; entitles: boolean }[];
};

export default function FinanceSubscriptions({
    subscriptions,
    pagination,
    filters,
    statuses,
}: SubscriptionsProps) {
    const { apply } = useListFilters(subscriptionsRoute.url(), filters);
    const filtered = filters.q !== '' || filters.status !== null;

    return (
        <>
            <Head title="Subscriptions" />

            <div className="space-y-6 p-4">
                <PageHeader
                    title="Finance"
                    description="Every subscription, newest first, with whether it still grants access."
                />

                <FinanceTabs />

                <div className="flex flex-col gap-2 sm:flex-row sm:items-center">
                    <SearchField
                        label="Search subscriptions"
                        placeholder="Pilot name, email or Creem ID"
                        value={filters.q}
                        onSearch={(q) => apply({ q })}
                    />
                    <FilterSelect
                        label="Filter by status"
                        allLabel="Any status"
                        value={filters.status}
                        options={statuses.map((status) => ({
                            value: status.value,
                            label: humanizeStatus(status.value),
                        }))}
                        onChange={(status) => apply({ status })}
                    />
                </div>

                <DataTable
                    caption="Subscriptions"
                    columns={subscriptionColumns()}
                    rows={subscriptions}
                    rowKey={(subscription) => subscription.creemId}
                    empty={
                        <EmptyState className="rounded-none border-0">
                            <EmptyStateTitle>
                                {filtered
                                    ? 'No matching subscriptions'
                                    : 'No subscriptions yet'}
                            </EmptyStateTitle>
                            <EmptyStateDescription>
                                {filtered
                                    ? 'Try a different name, address or status.'
                                    : 'Subscriptions appear when Creem reports one.'}
                            </EmptyStateDescription>
                        </EmptyState>
                    }
                />

                <ListPagination
                    pagination={pagination}
                    noun="subscription"
                    href={(page) =>
                        subscriptionsRoute.url({
                            query: {
                                ...(filters.q ? { q: filters.q } : {}),
                                ...(filters.status
                                    ? { status: filters.status }
                                    : {}),
                                page,
                            },
                        })
                    }
                />
            </div>
        </>
    );
}

FinanceSubscriptions.layout = {
    breadcrumbs: [
        { title: 'Admin', href: dashboard() },
        { title: 'Finance', href: finance() },
        { title: 'Subscriptions', href: subscriptionsRoute() },
    ],
};
