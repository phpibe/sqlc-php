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
 * IMPORTANT — Supported query shapes:
 * @optional is only safe when the rewriter can guarantee it only touches
 * WHERE clauses. Queries that contain any of the following constructs will
 * produce a fatal error at generation time:
 *
 *   - JOIN (INNER, LEFT, RIGHT, CROSS, FULL) — params in ON conditions
 *     would be rewritten incorrectly, turning a join condition into an
 *     optional filter and potentially producing cartesian products.
 *
 *   - Subqueries in WHERE (IN / EXISTS) — the rewriter cannot distinguish
 *     between the outer WHERE and a nested SELECT's WHERE.
 *
 *   - HAVING — semantically different from WHERE; skipping a HAVING
 *     condition has different implications than skipping a row filter.
 *
 * The correct general solution requires a full SQL AST parser with
 * a SQL serializer, which is out of scope for this project.
 */
class SqlRewriter
{
    private const OPERATORS = [
        '>=', '<=', '<>', '!=',   // multi-char first to avoid partial matches
        '=', '>', '<',
        'LIKE', 'ILIKE',
    ];

    /**
     * Unsafe constructs: pattern => human-readable reason.
     *
     * @var array<string, string>
     */
    private const UNSAFE_PATTERNS = [
        '/\b(INNER|LEFT|RIGHT|CROSS|FULL)\s+JOIN\b/i'
            => 'JOIN clauses (params in ON conditions would be rewritten incorrectly)',
        '/\bHAVING\b/i'
            => 'HAVING clauses (semantically different from WHERE)',
        '/\b(IN|EXISTS)\s*\(\s*SELECT\b/i'
            => 'subqueries in WHERE (IN / EXISTS)',
    ];

    /**
     * Rewrite the SQL for all listed optional parameter names.
     *
     * @param  string   $sql            Original SQL
     * @param  string[] $optionalParams Parameter names (without leading colon)
     * @param  string   $queryName      Query name for error messages
     * @return string                   Rewritten SQL
     *
     * @throws \RuntimeException if the query contains constructs unsafe for @optional
     */
    public function rewrite(string $sql, array $optionalParams, string $queryName = ''): string
    {
        if (empty($optionalParams)) {
            return $sql;
        }

        $this->assertSafeForOptional($sql, $queryName);

        foreach ($optionalParams as $paramName) {
            $sql = $this->rewriteParam($sql, $paramName);
        }

        return $sql;
    }

    // -------------------------------------------------------------------------

    /**
     * Throws a RuntimeException if the SQL contains any construct that makes
     * regex-based optional-param rewriting unsafe.
     *
     * @throws \RuntimeException
     */
    private function assertSafeForOptional(string $sql, string $queryName): void
    {
        $prefix = $queryName !== ''
            ? "Query '{$queryName}': @optional"
            : '@optional';

        foreach (self::UNSAFE_PATTERNS as $pattern => $reason) {
            if (preg_match($pattern, $sql)) {
                throw new \RuntimeException(
                    "{$prefix} is not supported on queries with {$reason}. " .
                    "Rewrite the condition manually in PHP or split the query."
                );
            }
        }
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
