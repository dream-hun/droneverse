import { Head, Link } from '@inertiajs/react';
import { UserPlus } from 'lucide-react';
import { FilterSelect } from '@/components/admin/filter-select';
import { ListPagination } from '@/components/admin/list-pagination';
import { SearchField } from '@/components/admin/search-field';
import { useUserActions } from '@/components/admin/user-actions';
import { UserFormDialog } from '@/components/admin/user-form-dialog';
import { DataTable } from '@/components/data-table';
import { PageHeader } from '@/components/page-header';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    EmptyState,
    EmptyStateDescription,
    EmptyStateTitle,
} from '@/components/ui/empty-state';
import { useListFilters } from '@/hooks/use-list-filters';
import { formatCount, formatDate } from '@/lib/admin-format';
import type { ColumnDef } from '@/lib/data-table';
import { dashboard } from '@/routes/admin';
import { index, show } from '@/routes/admin/users';
import type { AdminUser, Pagination, UserFormOptions } from '@/types/admin';

type UsersIndexProps = UserFormOptions & {
    users: AdminUser[];
    pagination: Pagination;
    filters: { q: string; role: string };
};

const columns: ColumnDef<AdminUser>[] = [
    {
        id: 'name',
        header: 'Account',
        cell: (user) => (
            <div className="min-w-0">
                <Link
                    href={show(user.uuid)}
                    className="block truncate font-medium hover:underline"
                >
                    {user.name}
                </Link>
                <span className="block truncate text-xs text-muted-foreground">
                    {user.email}
                    {!user.emailVerified && ' · unverified'}
                </span>
            </div>
        ),
    },
    {
        id: 'plan',
        header: 'Plan',
        cell: (user) => (
            <div className="flex flex-wrap items-center gap-1">
                <Badge
                    variant={
                        user.plan.value === 'starter' ? 'outline' : 'secondary'
                    }
                >
                    {user.plan.label}
                </Badge>
                {user.planOverride && (
                    <span className="text-xs text-muted-foreground">
                        set by hand
                    </span>
                )}
            </div>
        ),
    },
    {
        id: 'roles',
        header: 'Roles',
        hideBelow: 'md',
        cell: (user) =>
            user.roles.length > 0 ? (
                <div className="flex flex-wrap gap-1">
                    {user.roles.map((role) => (
                        <Badge
                            key={role}
                            variant={role === 'admin' ? 'default' : 'outline'}
                        >
                            {role}
                        </Badge>
                    ))}
                </div>
            ) : (
                <span className="text-muted-foreground">—</span>
            ),
    },
    {
        id: 'runs',
        header: 'Runs',
        align: 'end',
        hideBelow: 'lg',
        cell: (user) => formatCount(user.runs),
    },
    {
        id: 'joined',
        header: 'Joined',
        align: 'end',
        hideBelow: 'sm',
        cell: (user) => formatDate(user.createdAt),
    },
];

export default function UsersIndex({
    users,
    pagination,
    filters,
    roles,
    plans,
    can,
}: UsersIndexProps) {
    const options: UserFormOptions = { roles, plans, can };
    const { actionsFor, dialogs } = useUserActions(options);
    const { apply } = useListFilters(index.url(), filters);

    const filtered = filters.q !== '' || filters.role !== '';

    return (
        <>
            <Head title="Users" />

            <div className="space-y-6 p-4">
                <PageHeader
                    title="Users"
                    description="Every account on the platform, newest first."
                    actions={
                        <UserFormDialog
                            options={options}
                            trigger={
                                <Button>
                                    <UserPlus />
                                    New account
                                </Button>
                            }
                        />
                    }
                />

                <div className="flex flex-col gap-2 sm:flex-row sm:items-center">
                    <SearchField
                        label="Search accounts"
                        placeholder="Name or email"
                        value={filters.q}
                        onSearch={(q) => apply({ q })}
                    />
                    <FilterSelect
                        label="Filter by role"
                        allLabel="Everyone"
                        value={filters.role === '' ? null : filters.role}
                        options={[
                            { value: 'staff', label: 'Any staff role' },
                            ...roles.map((role) => ({
                                value: role.name,
                                label: role.name,
                            })),
                        ]}
                        onChange={(role) => apply({ role: role ?? '' })}
                    />
                </div>

                <DataTable
                    caption="Accounts"
                    columns={columns}
                    rows={users}
                    rowKey={(user) => user.uuid}
                    actions={(user) => actionsFor(user)}
                    actionsLabel={(user) => `Actions for ${user.name}`}
                    empty={
                        <EmptyState className="rounded-none border-0">
                            <EmptyStateTitle>
                                {filtered
                                    ? 'No matching accounts'
                                    : 'No accounts yet'}
                            </EmptyStateTitle>
                            <EmptyStateDescription>
                                {filtered
                                    ? 'Try a different name, address or role.'
                                    : 'Accounts appear here as pilots sign up.'}
                            </EmptyStateDescription>
                        </EmptyState>
                    }
                />

                <ListPagination
                    pagination={pagination}
                    noun="account"
                    href={(page) =>
                        index.url({
                            query: {
                                ...(filters.q ? { q: filters.q } : {}),
                                ...(filters.role ? { role: filters.role } : {}),
                                page,
                            },
                        })
                    }
                />
            </div>

            {dialogs}
        </>
    );
}

UsersIndex.layout = {
    breadcrumbs: [
        { title: 'Admin', href: dashboard() },
        { title: 'Users', href: index() },
    ],
};
