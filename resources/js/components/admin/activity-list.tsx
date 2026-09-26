import type { LucideIcon } from 'lucide-react';
import {
    CircleSlash,
    CreditCard,
    ListChecks,
    PlaneTakeoff,
    Repeat,
    Undo2,
    UserPlus,
} from 'lucide-react';
import { PilotLink } from '@/components/admin/pilot-link';
import { formatDateTime, formatRelative } from '@/lib/admin-format';
import type { ActivityItem, ActivityKind } from '@/types/admin';

/** What each source is called in a filter, singular. */
export const ACTIVITY_LABELS: Record<ActivityKind, string> = {
    signup: 'Sign-ups',
    run: 'Mission runs',
    quiz: 'Quiz attempts',
    order: 'Orders',
    refund: 'Refunds',
    subscription: 'New subscriptions',
    cancellation: 'Cancellations',
};

const ICONS: Record<ActivityKind, LucideIcon> = {
    signup: UserPlus,
    run: PlaneTakeoff,
    quiz: ListChecks,
    order: CreditCard,
    refund: Undo2,
    subscription: Repeat,
    cancellation: CircleSlash,
};

/**
 * The platform's recent events as a single list, newest first.
 *
 * Each row's kind is carried by an icon *and* its title's verb — "Flew",
 * "Paid", "Refunded" — so nothing depends on recognising an icon alone.
 */
export function ActivityList({ items }: { items: ActivityItem[] }) {
    return (
        <ol className="divide-y rounded-xl border bg-card">
            {items.map((item) => {
                const Icon = ICONS[item.kind];

                return (
                    <li
                        key={item.key}
                        className="flex items-start gap-3 px-4 py-3 text-sm"
                    >
                        <Icon
                            aria-hidden="true"
                            className="mt-0.5 size-4 shrink-0 text-muted-foreground"
                        />
                        <div className="min-w-0 flex-1 space-y-0.5">
                            <p className="font-medium">{item.title}</p>
                            {item.meta && (
                                <p className="text-xs text-muted-foreground">
                                    {item.meta}
                                </p>
                            )}
                        </div>
                        <div className="hidden w-48 shrink-0 text-xs sm:block">
                            <PilotLink pilot={item.pilot} />
                        </div>
                        <time
                            dateTime={item.occurredAt}
                            title={formatDateTime(item.occurredAt)}
                            className="w-24 shrink-0 text-right text-xs text-muted-foreground"
                        >
                            {formatRelative(item.occurredAt)}
                        </time>
                    </li>
                );
            })}
        </ol>
    );
}
