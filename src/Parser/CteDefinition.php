<?php

declare(strict_types=1);

namespace SqlcPhp\Parser;

/**
 * A named CTE definition loaded from a .sql file annotated with @cte.
 *
 * Example source:
 *   -- @cte active_users
 *   SELECT id, email FROM users WHERE active = 1;
 *
 * The `sql` property holds only the body (the SELECT), without the trailing
 * semicolon. The caller wraps it as:
 *   WITH active_users AS ( SELECT id, email FROM users WHERE active = 1 )
 */
readonly class CteDefinition
{
    public function __construct(
        /** The CTE name — used in WITH name AS (...) and matched by @use */
        public string $name,
        /** The CTE body SQL (SELECT ...), without trailing semicolon */
        public string $sql,
        /** Source file path — used in error messages */
        public string $sourceFile = '',
    ) {}
}
