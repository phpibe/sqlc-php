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
     * @return string                   Rewritten SQL
     */
    public function rewrite(string $sql, array $optionalParams): string
    {
        if (empty($optionalParams)) {
            return $sql;
        }

        foreach ($optionalParams as $paramName) {
            $sql = $this->rewriteParam($sql, $paramName);
        }

        return $sql;
    }

    private function rewriteParam(string $sql, string $paramName): string
    {
        $token = ':' . preg_quote($paramName, '/');

        // Build alternation of all operators, longest first (already ordered above)
        $opPattern = implode('|', array_map(
            fn(string $op) => preg_quote($op, '/'),
            self::OPERATORS
        ));

        // Match:  [table.]col  OP  :param
        // Allows optional backtick/double-quote quoting and table prefix.
        $pattern = '/
            (                               # capture group 1: full LHS + operator
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

        // Replace every occurrence
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
