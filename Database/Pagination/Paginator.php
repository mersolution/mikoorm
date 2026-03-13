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
}
