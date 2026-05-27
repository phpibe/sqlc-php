<?php

declare(strict_types=1);

namespace SqlcPhp\Rewriter;

/**
 * Rewrites SQL WHERE conditions for parameters marked @optional.
 *
 * For each optional parameter, every occurrence of:
 *
 *   col OP :param
 *
 * is rewritten to:
 *
 *   (:param IS NULL OR col OP :param)
 *
 * This means passing NULL for the parameter skips the filter entirely,
 * while passing a value filters normally — without any PHP-side conditionals.
 *
 * Supported operators: =  <>  !=  >  <  >=  <=  LIKE  ILIKE
 *
 * All occurrences of a given :param in the SQL are rewritten, including
 * repeated references (e.g. `WHERE a = :x AND b = :x`).
 *
 * Safety model:
 * The rewriter checks each optional parameter individually. A parameter is
 * unsafe when it appears inside constructs where the rewrite would produce
 * incorrect SQL:
 *
 *   - Inside a JOIN ... ON clause   — would turn a join condition into an
 *     optional filter, potentially producing cartesian products.
 *   - Inside BETWEEN :x AND :y      — not a binary col OP :param pattern.
 *   - Inside IN/EXISTS subqueries   — nested WHERE cannot be distinguished.
 *
 * Queries with JOINs or HAVING are allowed as long as the optional params
 * only appear in the WHERE clause. This was the most common false positive
 * in v2.4.0 — JOIN queries with WHERE-only optional params were rejected.
 */
class SqlRewriter
{
    private const OPERATORS = [
        '>=', '<=', '<>', '!=',   // multi-char first to avoid partial matches
        '=', '>', '<',
        'LIKE', 'ILIKE',
    ];

    /**
     * Rewrite the SQL for all listed optional parameter names.
     *
     * @param  string   $sql            Original SQL
     * @param  string[] $optionalParams Parameter names (without leading colon)
     * @param  string   $queryName      Query name for error messages
     * @return string                   Rewritten SQL
     *
     * @throws \RuntimeException if any optional param appears in an unsafe location
     */
    public function rewrite(string $sql, array $optionalParams, string $queryName = ''): string
    {
        if (empty($optionalParams)) {
            return $sql;
        }

        foreach ($optionalParams as $paramName) {
            $this->assertParamSafe($sql, $paramName, $queryName);
            $sql = $this->rewriteParam($sql, $paramName);
        }

        return $sql;
    }

    // -------------------------------------------------------------------------

    /**
     * Throws if the given :paramName appears in an unsafe location within the SQL.
     *
     * @throws \RuntimeException
     */
    private function assertParamSafe(string $sql, string $paramName, string $queryName): void
    {
        $prefix = $queryName !== ''
            ? "Query '{$queryName}': @optional '{$paramName}'"
            : "@optional '{$paramName}'";

        $token = ':' . preg_quote($paramName, '/');

        // ── BETWEEN :x AND :y  — cannot be rewritten as a single col OP :param ──
        if (preg_match('/\bBETWEEN\s+' . $token . '\s+AND\s+:[a-zA-Z_]\w*/i', $sql) ||
            preg_match('/\bBETWEEN\s+:[a-zA-Z_]\w*\s+AND\s+' . $token . '/i', $sql)) {
            throw new \RuntimeException(
                "{$prefix} appears in a BETWEEN clause. " .
                "BETWEEN with named params cannot be rewritten safely."
            );
        }

        // ── IN/EXISTS subqueries ─────────────────────────────────────────────
        // A rough heuristic: if the param appears near a subquery opening,
        // the context is ambiguous for the regex rewriter.
        if (preg_match('/\b(?:IN|EXISTS)\s*\(\s*SELECT\b.*?' . $token . '/is', $sql)) {
            throw new \RuntimeException(
                "{$prefix} appears inside an IN/EXISTS subquery. " .
                "Rewrite the condition manually in PHP or split the query."
            );
        }

        // ── JOIN ON clause ───────────────────────────────────────────────────
        // Check if :param appears inside any JOIN ... ON (...) block.
        // Extract the ON clause(s) and look for the token there.
        if ($this->paramAppearsInOnClause($sql, $paramName)) {
            throw new \RuntimeException(
                "{$prefix} appears inside a JOIN ON clause. " .
                "Rewriting a JOIN condition as optional would produce incorrect SQL " .
                "(potential cartesian product). Move the condition to WHERE instead."
            );
        }

        // ── WHERE clause presence check ──────────────────────────────────────
        // Ensure the param appears in a rewritable col OP :param pattern somewhere.
        // If it doesn't match, the rewrite is a no-op — warn instead of silently
        // doing nothing (a common mistake is misspelling the param name).
        $opPattern = implode('|', array_map(fn($op) => preg_quote($op, '/'), self::OPERATORS));
        $matchPattern = '/[`"]?\w+[`"]?(?:\.[`"]?\w+[`"]?)?\s*(?:' . $opPattern . ')\s*' . $token . '\b/i';

        if (!preg_match($matchPattern, $sql)) {
            throw new \RuntimeException(
                "{$prefix} does not match any 'col OP :param' pattern in the SQL. " .
                "Check the param name or ensure the condition is a simple comparison."
            );
        }
    }

    /**
     * Returns true if :paramName appears inside a JOIN ... ON block.
     *
     * Strategy: scan for JOIN keywords, then find the associated ON clause,
     * and check if the token appears before the next WHERE/GROUP/ORDER/HAVING/LIMIT.
     */
    private function paramAppearsInOnClause(string $sql, string $paramName): bool
    {
        $token = ':' . $paramName;

        // Extract all ON clause content (text after ON up to WHERE/GROUP/ORDER/HAVING/LIMIT/JOIN)
        // This handles both simple "ON a.id = b.id" and compound "ON (... AND ...)"
        $onPattern = '/\bON\b\s*(.+?)(?=\bWHERE\b|\bGROUP\b|\bORDER\b|\bHAVING\b|\bLIMIT\b|\bLEFT\b|\bRIGHT\b|\bINNER\b|\bCROSS\b|\bFULL\b|\bJOIN\b|$)/is';

        if (!preg_match_all($onPattern, $sql, $matches)) {
            return false;
        }

        foreach ($matches[1] as $onContent) {
            if (str_contains(strtolower($onContent), strtolower($token))) {
                return true;
            }
        }

        return false;
    }

    private function rewriteParam(string $sql, string $paramName): string
    {
        $token = ':' . preg_quote($paramName, '/');

        $opPattern = implode('|', array_map(
            fn(string $op) => preg_quote($op, '/'),
            self::OPERATORS
        ));

        $pattern = '/
            (                               # capture group 1: LHS column ref
                [`"]?\w+[`"]?               # optional table qualifier ...
                \.                          #   ... dot
                [`"]?\w+[`"]?               # ... column name
                |                           # OR
                [`"]?\w+[`"]?               # bare column name
            )
            \s*
            (                               # capture group 2: operator
                ' . $opPattern . '
            )
            \s*
            (' . $token . '\b)              # capture group 3: :paramName
        /ix';

        $sql = preg_replace_callback(
            $pattern,
            function (array $m) use ($paramName): string {
                $lhs = $m[1];
                $op  = $m[2];
                $ref = ":{$paramName}";
                return "({$ref} IS NULL OR {$lhs} {$op} {$ref})";
            },
            $sql
        ) ?? $sql;

        return $sql;
    }
}
