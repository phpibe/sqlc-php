<?php

declare(strict_types=1);

namespace SqlcPhp\Query;

/**
 * Result of a @paginate query — items + pagination metadata in one object.
 *
 * Returned by generated methods annotated with @paginate. The $items array
 * contains the typed model/DTO objects for the requested page. The metadata
 * fields describe the full result set so the caller can build pagination UI
 * without a second query.
 *
 * PHPDoc generics (understood by PhpStorm, Intelephense, Psalm, PHPStan):
 *
 * @template T
 */
readonly class PaginatedResult
{
    /**
     * @param array<T> $items    The rows for the current page.
     * @param int      $total    Total number of rows matching the query (all pages).
     * @param int      $limit    Number of rows per page (as requested).
     * @param int      $offset   Number of rows skipped (as requested).
     * @param int      $pages    Total number of pages: ceil($total / $limit). 0 when $total is 0.
     * @param bool     $hasMore  True when there are rows after the current page.
     */
    public function __construct(
        public array $items,
        public int   $total,
        public int   $limit,
        public int   $offset,
        public int   $pages,
        public bool  $hasMore,
    ) {}

    /**
     * The current page number (1-based).
     * Returns 1 even when the result set is empty.
     */
    public function currentPage(): int
    {
        if ($this->limit <= 0) return 1;
        return (int) floor($this->offset / $this->limit) + 1;
    }

    /**
     * True when this is the first page (offset === 0).
     */
    public function isFirstPage(): bool
    {
        return $this->offset === 0;
    }

    /**
     * True when this is the last page (no more rows after this one).
     */
    public function isLastPage(): bool
    {
        return !$this->hasMore;
    }

    /**
     * Offset for the next page, or null when there is no next page.
     */
    public function nextOffset(): ?int
    {
        return $this->hasMore ? $this->offset + $this->limit : null;
    }

    /**
     * Offset for the previous page, or null when on the first page.
     */
    public function previousOffset(): ?int
    {
        if ($this->offset <= 0) return null;
        return max(0, $this->offset - $this->limit);
    }
}
