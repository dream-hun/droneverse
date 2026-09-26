import { Link } from '@inertiajs/react';
import { Button } from '@/components/ui/button';
import type { Pagination } from '@/types/admin';

type ListPaginationProps = {
    pagination: Pagination;
    /** The URL of a given page, current filters included. */
    href: (page: number) => string;
    /** What is being counted, singular — "account", "order". */
    noun: string;
};

/**
 * Previous and next for a server-paginated admin list, with the total.
 *
 * Previous and next rather than numbered pages: these lists are read from the
 * newest end, and the total says how deep they go without offering a jump to
 * page 400 that nobody makes.
 */
export function ListPagination({
    pagination,
    href,
    noun,
}: ListPaginationProps) {
    const { page, lastPage, total } = pagination;
    const label = `${total.toLocaleString()} ${noun}${total === 1 ? '' : 's'}`;

    return (
        <nav
            aria-label="Pagination"
            className="flex flex-wrap items-center justify-between gap-3 text-sm text-muted-foreground"
        >
            <span>
                {label}
                {lastPage > 1 && ` · page ${page} of ${lastPage}`}
            </span>

            {lastPage > 1 && (
                <div className="flex items-center gap-2">
                    <Button
                        variant="outline"
                        size="sm"
                        disabled={page <= 1}
                        asChild={page > 1}
                    >
                        {page > 1 ? (
                            <Link
                                href={href(page - 1)}
                                preserveScroll
                                preserveState
                            >
                                Previous
                            </Link>
                        ) : (
                            <span>Previous</span>
                        )}
                    </Button>
                    <Button
                        variant="outline"
                        size="sm"
                        disabled={page >= lastPage}
                        asChild={page < lastPage}
                    >
                        {page < lastPage ? (
                            <Link
                                href={href(page + 1)}
                                preserveScroll
                                preserveState
                            >
                                Next
                            </Link>
                        ) : (
                            <span>Next</span>
                        )}
                    </Button>
                </div>
            )}
        </nav>
    );
}
