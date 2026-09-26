<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * Where a paginated admin list is, in the shape its pager reads.
 *
 * The rows travel separately, through their own resource, so a page's props
 * name what they hold — `users`, `orders` — rather than every list arriving as
 * a `data` key inside Laravel's paginator envelope.
 */
final class PaginationResource
{
    /**
     * @template TValue
     *
     * @param  LengthAwarePaginator<int, TValue>  $paginator
     * @return array{page: int, lastPage: int, total: int, perPage: int}
     */
    public static function of(LengthAwarePaginator $paginator): array
    {
        return [
            'page' => $paginator->currentPage(),
            'lastPage' => $paginator->lastPage(),
            'total' => $paginator->total(),
            'perPage' => $paginator->perPage(),
        ];
    }
}
