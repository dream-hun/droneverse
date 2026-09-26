import { PilotLink } from '@/components/admin/pilot-link';
import { Badge } from '@/components/ui/badge';
import { formatDate, humanizeStatus } from '@/lib/admin-format';
import type { ColumnDef } from '@/lib/data-table';
import type { AdminOrder, AdminSubscription } from '@/types/admin';

function pilotColumn<
    TRow extends AdminOrder | AdminSubscription,
>(): ColumnDef<TRow> {
    return {
        id: 'pilot',
        header: 'Pilot',
        cell: (row) => (
            <div className="max-w-56 text-xs">
                <PilotLink pilot={row.pilot} />
            </div>
        ),
    };
}

/**
 * The ledger's columns. `withPilot` is off on an account's own page, where
 * every row belongs to the same person.
 */
export function orderColumns({
    withPilot = true,
} = {}): ColumnDef<AdminOrder>[] {
    return [
        {
            id: 'orderedAt',
            header: 'Date',
            cell: (order) => formatDate(order.orderedAt),
        },
        ...(withPilot ? [pilotColumn<AdminOrder>()] : []),
        {
            id: 'product',
            header: 'Product',
            hideBelow: 'md',
            cell: (order) => order.product,
        },
        {
            id: 'amount',
            header: 'Amount',
            align: 'end',
            cell: (order) => (
                <div className="tabular-nums">
                    <span
                        className={
                            order.refunded
                                ? 'line-through opacity-60'
                                : undefined
                        }
                    >
                        {order.amount}
                    </span>
                    {order.refundedAmount && (
                        <span className="block text-xs text-muted-foreground">
                            {order.refundedAmount} refunded
                        </span>
                    )}
                </div>
            ),
        },
        {
            id: 'status',
            header: 'Status',
            align: 'end',
            hideBelow: 'sm',
            cell: (order) =>
                order.refunded ? (
                    <Badge variant="destructive">Refunded</Badge>
                ) : (
                    <Badge variant="outline">
                        {humanizeStatus(order.status)}
                    </Badge>
                ),
        },
    ];
}

export function subscriptionColumns({
    withPilot = true,
} = {}): ColumnDef<AdminSubscription>[] {
    return [
        ...(withPilot ? [pilotColumn<AdminSubscription>()] : []),
        {
            id: 'product',
            header: 'Product',
            cell: (subscription) => (
                <div>
                    <span className="block">{subscription.product}</span>
                    {subscription.units > 1 && (
                        <span className="text-xs text-muted-foreground">
                            {subscription.units} seats
                        </span>
                    )}
                </div>
            ),
        },
        {
            id: 'status',
            header: 'Status',
            cell: (subscription) => (
                <div className="flex flex-wrap items-center gap-1">
                    <Badge
                        variant={
                            subscription.entitles ? 'secondary' : 'outline'
                        }
                    >
                        {humanizeStatus(subscription.status)}
                    </Badge>
                    {!subscription.entitles && (
                        <span className="text-xs text-muted-foreground">
                            no access
                        </span>
                    )}
                </div>
            ),
        },
        {
            id: 'dates',
            header: 'Renews / ends',
            align: 'end',
            hideBelow: 'sm',
            cell: (subscription) =>
                subscription.endsAt
                    ? `Ends ${formatDate(subscription.endsAt)}`
                    : subscription.renewsAt
                      ? `Renews ${formatDate(subscription.renewsAt)}`
                      : '—',
        },
        {
            id: 'createdAt',
            header: 'Started',
            align: 'end',
            hideBelow: 'lg',
            cell: (subscription) => formatDate(subscription.createdAt),
        },
    ];
}
