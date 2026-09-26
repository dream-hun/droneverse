import { Head } from '@inertiajs/react';
import { FilterSelect } from '@/components/admin/filter-select';
import { orderColumns } from '@/components/admin/finance-columns';
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
import { dashboard, finance } from '@/routes/admin';
import { orders as ordersRoute } from '@/routes/admin/finance';
import type { AdminOrder, Pagination } from '@/types/admin';

type OrdersProps = {
    orders: AdminOrder[];
    pagination: Pagination;
    filters: { q: string; refunded: 'yes' | 'no' | null };
};

export default function FinanceOrders({
    orders,
    pagination,
    filters,
}: OrdersProps) {
    const { apply } = useListFilters(ordersRoute.url(), filters);
    const filtered = filters.q !== '' || filters.refunded !== null;

    return (
        <>
            <Head title="Orders" />

            <div className="space-y-6 p-4">
                <PageHeader
                    title="Finance"
                    description="Every order Creem has reported, newest first."
                />

                <FinanceTabs />

                <div className="flex flex-col gap-2 sm:flex-row sm:items-center">
                    <SearchField
                        label="Search orders"
                        placeholder="Pilot name, email or Creem order ID"
                        value={filters.q}
                        onSearch={(q) => apply({ q })}
                    />
                    <FilterSelect
                        label="Filter by refund"
                        allLabel="All orders"
                        value={filters.refunded}
                        options={[
                            { value: 'no', label: 'Not refunded' },
                            { value: 'yes', label: 'Refunded' },
                        ]}
                        onChange={(refunded) => apply({ refunded })}
                    />
                </div>

                <DataTable
                    caption="Orders"
                    columns={orderColumns()}
                    rows={orders}
                    rowKey={(order) => order.creemId}
                    empty={
                        <EmptyState className="rounded-none border-0">
                            <EmptyStateTitle>
                                {filtered
                                    ? 'No matching orders'
                                    : 'No orders yet'}
                            </EmptyStateTitle>
                            <EmptyStateDescription>
                                {filtered
                                    ? 'Try a different name, address or order ID.'
                                    : 'Orders appear when Creem reports a completed checkout.'}
                            </EmptyStateDescription>
                        </EmptyState>
                    }
                />

                <ListPagination
                    pagination={pagination}
                    noun="order"
                    href={(page) =>
                        ordersRoute.url({
                            query: {
                                ...(filters.q ? { q: filters.q } : {}),
                                ...(filters.refunded
                                    ? { refunded: filters.refunded }
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

FinanceOrders.layout = {
    breadcrumbs: [
        { title: 'Admin', href: dashboard() },
        { title: 'Finance', href: finance() },
        { title: 'Orders', href: ordersRoute() },
    ],
};
