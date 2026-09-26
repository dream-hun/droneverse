import { Link } from '@inertiajs/react';
import { useCurrentUrl } from '@/hooks/use-current-url';
import { cn } from '@/lib/utils';
import { finance } from '@/routes/admin';
import {
    orders as financeOrders,
    subscriptions as financeSubscriptions,
} from '@/routes/admin/finance';

const TABS = [
    { title: 'Overview', href: finance() },
    { title: 'Orders', href: financeOrders() },
    { title: 'Subscriptions', href: financeSubscriptions() },
];

/** The three finance screens, as one section with tabs between them. */
export function FinanceTabs() {
    const { isCurrentUrl } = useCurrentUrl();

    return (
        <nav aria-label="Finance" className="flex gap-1 border-b">
            {TABS.map((tab) => {
                const current = isCurrentUrl(tab.href);

                return (
                    <Link
                        key={tab.title}
                        href={tab.href}
                        aria-current={current ? 'page' : undefined}
                        className={cn(
                            '-mb-px border-b-2 px-3 py-2 text-sm transition-colors',
                            current
                                ? 'border-primary font-medium text-foreground'
                                : 'border-transparent text-muted-foreground hover:text-foreground',
                        )}
                    >
                        {tab.title}
                    </Link>
                );
            })}
        </nav>
    );
}
