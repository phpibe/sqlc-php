<?php

declare(strict_types=1);

namespace SqlcPhp\Query;

/**
 * Result of a :cursor query — items for the current page plus an opaque
 * cursor token for fetching the next page.
 *
 * Unlike offset pagination (PaginatedResult), cursor pagination:
 *   - Does NOT run a COUNT(*) query — no total or page count
 *   - Is O(1) regardless of which page you are on (no OFFSET scan)
 *   - Guarantees consistent reads — inserted rows between pages don't
 *     cause items to be skipped or duplicated
 *   - Only supports forward iteration (nextCursor); the cursor is tied
 *     to the ORDER BY columns declared in @cursor
 *
 * PHPDoc generics (PhpStorm, Intelephense, Psalm, PHPStan):
 *
 * @template T
 */
readonly class CursorResult
{
    /**
     * @param array<T>    $items       The rows for the current page.
     * @param bool        $hasMore     True when there are more rows after this page.
     * @param string|null $nextCursor  Opaque token to pass as $after on the next call.
     *                                 Null on the last page.
     */
    public function __construct(
        public array   $items,
        public bool    $hasMore,
        public ?string $nextCursor,
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
