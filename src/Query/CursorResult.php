<?php

declare(strict_types=1);

namespace SqlcPhp\Query;

/**
 * Result of a :cursor query — items for the current page plus opaque cursor
 * tokens for forward and backward navigation.
 *
 * Unlike offset pagination (PaginatedResult), cursor pagination:
 *   - Does NOT run a COUNT(*) query — no total or page count
 *   - Is O(1) regardless of which page you are on (no OFFSET scan)
 *   - Guarantees consistent reads — inserted rows between pages don't
 *     cause items to be skipped or duplicated
 *   - Supports both forward ($after / nextCursor) and backward ($before / prevCursor)
 *     navigation
 *
 * Navigation:
 *
 *   // Forward
 *   $page2 = $repo->listOrders(after: $page1->nextCursor);
 *
 *   // Backward
 *   $page1 = $repo->listOrders(before: $page2->prevCursor);
 *
 * PHPDoc generics (PhpStorm, Intelephense, Psalm, PHPStan):
 *
 * @template T
 */
readonly class CursorResult
{
    /**
     * @param array<T>    $items       The rows for the current page.
     * @param bool        $hasMore     True when there are more rows after this page
     *                                 (i.e. a next page exists).
     * @param bool        $hasPrev     True when there are rows before this page
     *                                 (i.e. a previous page exists).
     *                                 Always false on the first page ($after was null
     *                                 and $before was null).
     * @param string|null $nextCursor  Opaque token — pass as $after to fetch the next page.
     *                                 Null on the last page.
     * @param string|null $prevCursor  Opaque token — pass as $before to fetch the previous page.
     *                                 Null on the first page.
     *                                 Built from the cursor-column values of the FIRST row
     *                                 of the current page.
     */
    public function __construct(
        public array   $items,
        public bool    $hasMore,
        public bool    $hasPrev,
        public ?string $nextCursor,
        public ?string $prevCursor,
    ) {}

    /**
     * Decode an opaque cursor token for debugging or testing.
     *
     * Returns the raw cursor column values, e.g.:
     *   ['created_at' => '2024-01-15 10:30:00', 'id' => 42]
     *
     * Returns null if the token is null, empty, or invalid.
     *
     * @return array<string, mixed>|null
     */
    public static function decodeCursor(?string $cursor): ?array
    {
        if ($cursor === null || $cursor === '') return null;
        $decoded = base64_decode($cursor, strict: true);
        if ($decoded === false) return null;
        $data = json_decode($decoded, associative: true);
        return is_array($data) ? $data : null;
    }

    /**
     * Encode cursor column values into an opaque token.
     * Used internally by generated methods — not needed in application code.
     *
     * @param array<string, mixed> $values
     */
    public static function encodeCursor(array $values): string
    {
        return base64_encode(json_encode($values, JSON_THROW_ON_ERROR));
    }
}
