<?php

declare(strict_types=1);

namespace SqlcPhp\Query;

/**
 * Immutable record of a query that was executed by a generated Query class.
 *
 * Every generated method saves its SQL and bindings here before returning.
 * Retrieve via $queryClass->lastQuery() immediately after the call.
 *
 * The object does NOT hold a PDO connection and cannot re-execute the query.
 * It exists purely for inspection: logging, debugging, testing, and caching.
 *
 * ---
 * Usage:
 *
 *   $users = $userRepo->listActiveUsers(active: 1);
 *   $q     = $userRepo->lastQuery();
 *
 *   // Safe SQL for logging (placeholders intact)
 *   $logger->debug($q->toString());
 *   // → SELECT * FROM users WHERE (:active_chk IS NULL OR active = :active)
 *
 *   // Interpolated SQL for debugging only — NEVER pass to PDO
 *   echo $q->toDebugSql();
 *   // → SELECT * FROM users WHERE (1 IS NULL OR active = 1)
 *
 *   // Cache key
 *   $cacheKey = $q->cacheKey();
 *
 *   // Test: verify the SQL without a database
 *   $this->assertStringContainsString('WHERE active', $q->toString());
 */
readonly class QueryObject
{
    /**
     * @param string  $sql        Raw SQL with named PDO placeholders (:param)
     * @param array<string, array{0: mixed, 1: int}> $bindings
     *                            Map of placeholder → [value, PDO::PARAM_* constant]
     * @param string  $queryName  The generated method name that produced this query
     * @param bool    $isBatch    True when the query was executed via :batch
     * @param int     $batchCount Number of rows processed (batch only)
     * @param float   $durationMs Execution time in milliseconds (hrtime precision).
     *                            0.0 means the query has not been executed yet or
     *                            timing was not captured (pre-execute snapshot).
     */
    public function __construct(
        public string $sql,
        public array  $bindings    = [],
        public string $queryName   = '',
        public bool   $isBatch     = false,
        public int    $batchCount  = 0,
        public float  $durationMs  = 0.0,
    ) {}

    // -------------------------------------------------------------------------
    // Immutable update
    // -------------------------------------------------------------------------

    /**
     * Return a new QueryObject with the measured duration set.
     *
     * Called automatically by every generated method after $stmt->execute()
     * completes. The original instance (without duration) is discarded.
     *
     * @param float $ms  Milliseconds, typically from hrtime(true) / 1_000_000
     */
    public function withDuration(float $ms): self
    {
        return new self(
            sql:        $this->sql,
            bindings:   $this->bindings,
            queryName:  $this->queryName,
            isBatch:    $this->isBatch,
            batchCount: $this->batchCount,
            durationMs: $ms,
        );
    }
    // -------------------------------------------------------------------------
    // Inspection
    // -------------------------------------------------------------------------

    /**
     * The SQL with named PDO placeholders — safe to log, store, or display.
     *
     * For @optional queries the internal :param_chk tokens are included —
     * they are an implementation detail but cause no harm in logs.
     */
    public function toString(): string
    {
        return $this->sql;
    }

    /**
     * The SQL with bound values interpolated inline.
     *
     * ⚠  FOR DEBUGGING ONLY — never pass to PDO or display to end users.
     *    Values are not properly escaped for safe query execution.
     */
    public function toDebugSql(): string
    {
        // Sort by key length descending so :param_chk is replaced before :param
        $keys = array_keys($this->bindings);
        usort($keys, fn($a, $b) => strlen($b) - strlen($a));

        $sql = $this->sql;
        foreach ($keys as $key) {
            [$value] = $this->bindings[$key];
            $display = match (true) {
                is_null($value)   => 'NULL',
                is_bool($value)   => $value ? '1' : '0',
                is_string($value) => "'" . addslashes((string) $value) . "'",
                $value instanceof \DateTimeInterface => "'" . $value->format('Y-m-d H:i:s') . "'",
                is_array($value)  => "'" . addslashes(json_encode($value) ?: '') . "'",
                default           => (string) $value,
            };
            $sql = str_replace($key, $display, $sql);
        }
        return $sql;
    }

    /**
     * Bound parameter values keyed by placeholder name.
     *
     * @return array<string, array{0: mixed, 1: int}>
     */
    public function bindings(): array
    {
        return $this->bindings;
    }

    /**
     * Bound values only (without PDO type constants).
     * Useful for simple logging or serialisation.
     *
     * @return array<string, mixed>
     */
    public function values(): array
    {
        return array_map(fn($b) => $b[0], $this->bindings);
    }

    /**
     * A stable cache key derived from the SQL and bound values.
     * Suitable for use with any PSR-6/PSR-16 cache.
     */
    public function cacheKey(): string
    {
        return md5($this->sql . serialize($this->values()));
    }

    /**
     * Number of bound parameters.
     */
    public function paramCount(): int
    {
        return count($this->bindings);
    }

    /**
     * Bindings as a flat array of values compatible with Laravel's QueryExecuted
     * constructor and Debugbar's QueryCollector.
     *
     * Differences from bindings() and values():
     *   - Returns a flat indexed array (no placeholder keys)
     *   - Filters out internal _chk params generated for @optional queries
     *     (they are implementation details, not real query parameters)
     *   - Filters out :limit and :offset params used for :many-paginated
     *
     * Usage with Debugbar:
     *   $qe = new QueryExecuted(
     *       $q->toString(),           // SQL with named placeholders
     *       $q->toDebugBindings(),    // flat values array, _chk filtered out
     *       $q->durationMs,
     *       $connection,
     *   );
     *
     * Or with toDebugSql() for even simpler integration (no interpolation needed):
     *   $qe = new QueryExecuted($q->toDebugSql(), [], $q->durationMs, $connection);
     *
     * @return list<mixed>  flat indexed array of values
     */
    public function toDebugBindings(): array
    {
        return array_values(
            array_filter(
                $this->values(),
                static fn(string $key): bool =>
                    !str_ends_with($key, '_chk')
                    && $key !== ':limit'
                    && $key !== ':offset',
                ARRAY_FILTER_USE_KEY,
            )
        );
    }

    public function __toString(): string
    {
        return $this->toString();
    }
}
