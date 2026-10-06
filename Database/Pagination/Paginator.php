<?php
/**
 * MIT License
 * Copyright (c) 2026 Mersolution Technology Ltd.
 * See LICENSE file for details.
 *
 * @contact hello@mersolution.com
 * @website https://www.mersolution.com/
 */

namespace Miko\Database\Pagination;

/**
 * Paginator - Handle pagination for query results
 */
class Paginator
{
    private array $items;
    private int $total;
    private int $perPage;
    private int $currentPage;
    private int $lastPage;

    public function __construct(array $items, int $total, int $perPage, int $currentPage)
    {
        $this->items = $items;
        $this->total = $total;
        $this->perPage = $perPage;
        $this->currentPage = max(1, $currentPage);
        $this->lastPage = max(1, (int)ceil($total / $perPage));
    }

    /**
     * Get paginated items
     */
    public function items(): array
    {
        return $this->items;
    }

    /**
     * Get total count
     */
    public function total(): int
    {
        return $this->total;
    }

    /**
     * Get per page count
     */
    public function perPage(): int
    {
        return $this->perPage;
    }

    /**
     * Get current page
     */
    public function currentPage(): int
    {
        return $this->currentPage;
    }

    /**
     * Get last page
     */
    public function lastPage(): int
    {
        return $this->lastPage;
    }

    /**
     * Check if has more pages
     */
    public function hasMorePages(): bool
    {
        return $this->currentPage < $this->lastPage;
    }

    /**
     * Check if on first page
     */
    public function onFirstPage(): bool
    {
        return $this->currentPage === 1;
    }

    /**
     * Check if on last page
     */
    public function onLastPage(): bool
    {
        return $this->currentPage === $this->lastPage;
    }

    /**
     * Get next page number
     */
    public function nextPage(): ?int
    {
        return $this->hasMorePages() ? $this->currentPage + 1 : null;
    }

    /**
     * Get previous page number
     */
    public function previousPage(): ?int
    {
        return $this->currentPage > 1 ? $this->currentPage - 1 : null;
    }

    /**
     * Get offset for current page
     */
    public function offset(): int
    {
        return ($this->currentPage - 1) * $this->perPage;
    }

    /**
     * Get from item number
     */
    public function from(): int
    {
        return $this->total > 0 ? $this->offset() + 1 : 0;
    }

    /**
     * Get to item number
     */
    public function to(): int
    {
        return min($this->offset() + $this->perPage, $this->total);
    }

    /**
     * Get page range for navigation
     */
    public function getPageRange(int $onEachSide = 3): array
    {
        $start = max(1, $this->currentPage - $onEachSide);
        $end = min($this->lastPage, $this->currentPage + $onEachSide);

        return range($start, $end);
    }

    /**
     * Convert to array
     */
    public function toArray(): array
    {
        return [
            'data' => $this->items,
            'meta' => [
                'current_page' => $this->currentPage,
                'last_page' => $this->lastPage,
                'per_page' => $this->perPage,
                'total' => $this->total,
                'from' => $this->from(),
                'to' => $this->to(),
                'has_more' => $this->hasMorePages()
            ]
        ];
    }

    /**
     * Convert to JSON
     */
    public function toJson(): string
    {
        return json_encode($this->toArray(), JSON_UNESCAPED_UNICODE);
    }

    /**
     * Paginate a raw SQL query
     * Automatically wraps the query with COUNT(*) for total and adds LIMIT/OFFSET
     *
     * @param \Miko\Database\Query\QueryBuilder $queryBuilder
     * @param string $sql Raw SQL query (without LIMIT/OFFSET)
     * @param array $bindings Parameter bindings
     * @param int $page Current page number (1-based)
     * @param int $perPage Items per page
     * @return array Paginated result with data and pagination meta
     */
    public static function rawPaginate(
        \Miko\Database\Query\QueryBuilder $queryBuilder,
        string $sql,
        array $bindings,
        int $page = 1,
        int $perPage = 50
    ): array {
        $page = max(1, $page);
        $perPage = max(1, min($perPage, 500));

        $grammar = $queryBuilder->getConnection()->getGrammar();
        $countSql = 'SELECT COUNT(*) AS aggregate FROM (' . $grammar->derivedTable($sql) . ') AS count_table';
        $countResult = $queryBuilder->rawQuery($countSql, $bindings);
        $total = (int) ($countResult[0]['aggregate'] ?? 0);

        $lastPage = max(1, (int) ceil($total / $perPage));
        /* İstenen sayfa toplam sayfayı aşmasın (aksi halde boş data + yanıltıcı current_page) */
        $page = min($page, $lastPage);
        $offset = ($page - 1) * $perPage;

        $hasOrder = \Miko\Database\Query\Grammar::hasOrderBy($sql);
        $paginatedSql = $sql . $grammar->compileLimit($perPage, $offset, $hasOrder);
        $data = $queryBuilder->rawQuery($paginatedSql, $bindings);

        return [
            'data' => $data,
            'pagination' => [
                'current_page' => $page,
                'last_page' => $lastPage,
                'per_page' => $perPage,
                'total' => $total,
                'from' => $total > 0 ? $offset + 1 : 0,
                'to' => min($offset + $perPage, $total),
                'has_more' => $page < $lastPage
            ]
        ];
    }

    /**
     * Read pagination params from GET request
     *
     * @param int $defaultPerPage Default items per page
     * @param int $maxPerPage Maximum allowed items per page
     * @return array [page, perPage]
     */
    public static function getRequestParams(int $defaultPerPage = 50, int $maxPerPage = 500): array
    {
        $page = max(1, (int) ($_GET['page'] ?? 1));
        $perPage = max(1, min((int) ($_GET['limit'] ?? $defaultPerPage), $maxPerPage));
        return [$page, $perPage];
    }
}
