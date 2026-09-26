import { Head, Link } from '@inertiajs/react';
import {
    ACTIVITY_LABELS,
    ActivityList,
} from '@/components/admin/activity-list';
import { FilterSelect } from '@/components/admin/filter-select';
import { PageHeader } from '@/components/page-header';
import { Button } from '@/components/ui/button';
import {
    EmptyState,
    EmptyStateDescription,
    EmptyStateTitle,
} from '@/components/ui/empty-state';
import { useListFilters } from '@/hooks/use-list-filters';
import { activity as activityRoute, dashboard } from '@/routes/admin';
import type { ActivityKind, ActivityPage } from '@/types/admin';

type ActivityProps = {
    activity: ActivityPage;
    /** The sources this viewer may read; money only with `view_finance`. */
    kinds: ActivityKind[];
    type: ActivityKind | null;
};

export default function AdminActivity({
    activity,
    kinds,
    type,
}: ActivityProps) {
    const url = activityRoute.url();
    const { apply } = useListFilters(url, { type });

    function pageHref(page: number): string {
        return activityRoute.url({
            query: { ...(type ? { type } : {}), page },
        });
    }

    return (
        <>
            <Head title="Activity" />

            <div className="space-y-6 p-4">
                <PageHeader
                    title="Activity"
                    description="Everything that has happened on the platform, newest first."
                    actions={
                        <FilterSelect
                            label="Show"
                            allLabel="Everything"
                            value={type}
                            options={kinds.map((kind) => ({
                                value: kind,
                                label: ACTIVITY_LABELS[kind],
                            }))}
                            onChange={(value) => apply({ type: value })}
                        />
                    }
                />

                {activity.items.length > 0 ? (
                    <ActivityList items={activity.items} />
                ) : (
                    <EmptyState>
                        <EmptyStateTitle>Nothing to show</EmptyStateTitle>
                        <EmptyStateDescription>
                            {type
                                ? 'Nothing of this kind has happened yet.'
                                : 'Sign-ups, runs and payments show up here as they happen.'}
                        </EmptyStateDescription>
                    </EmptyState>
                )}

                {(activity.page > 1 || activity.hasMore) && (
                    <nav
                        aria-label="Pagination"
                        className="flex items-center justify-center gap-3"
                    >
                        <Button
                            variant="outline"
                            size="sm"
                            disabled={activity.page <= 1}
                            asChild={activity.page > 1}
                        >
                            {activity.page > 1 ? (
                                <Link
                                    href={pageHref(activity.page - 1)}
                                    preserveScroll
                                >
                                    Newer
                                </Link>
                            ) : (
                                <span>Newer</span>
                            )}
                        </Button>
                        <span className="text-sm text-muted-foreground">
                            Page {activity.page}
                        </span>
                        <Button
                            variant="outline"
                            size="sm"
                            disabled={!activity.hasMore}
                            asChild={activity.hasMore}
                        >
                            {activity.hasMore ? (
                                <Link
                                    href={pageHref(activity.page + 1)}
                                    preserveScroll
                                >
                                    Older
                                </Link>
                            ) : (
                                <span>Older</span>
                            )}
                        </Button>
                    </nav>
                )}
            </div>
        </>
    );
}

AdminActivity.layout = {
    breadcrumbs: [
        { title: 'Admin', href: dashboard() },
        { title: 'Activity', href: activityRoute() },
    ],
};
