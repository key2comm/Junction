<?php

namespace App\Http\Controllers\Api\V1;

use Closure;
use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * List responses: {"data": [...], "meta": {...}}. Page-numbered lists use
 * ?page=&per_page= (max 100); call records use ?cursor= instead.
 */
trait Paginates
{
    protected function perPage(Request $request): int
    {
        $request->validate(['per_page' => ['nullable', 'integer', 'between:1,100'], 'page' => ['nullable', 'integer', 'min:1']]);

        return (int) $request->query('per_page', 50);
    }

    protected function paged(LengthAwarePaginator $page, Closure $map): JsonResponse
    {
        return response()->json([
            'data' => collect($page->items())->map($map)->values(),
            'meta' => ['page' => $page->currentPage(), 'per_page' => $page->perPage(), 'total' => $page->total(), 'last_page' => $page->lastPage()],
        ]);
    }

    protected function cursored(CursorPaginator $page, Closure $map): JsonResponse
    {
        return response()->json([
            'data' => collect($page->items())->map($map)->values(),
            'meta' => ['per_page' => $page->perPage(), 'next_cursor' => $page->nextCursor()?->encode()],
        ]);
    }
}
