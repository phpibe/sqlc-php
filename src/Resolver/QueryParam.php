<?php

declare(strict_types=1);

namespace SqlcPhp\Resolver;

/**
 * A named parameter extracted from a SQL query (e.g. :userId)
 * with its type resolved from the schema.
 */
class QueryParam
{
    public function __construct(
        /** Parameter name without the leading colon, e.g. "userId" */
        public readonly string  $name,
        /** SQL type resolved from the schema column, e.g. "INT" */
        public readonly string  $sqlType,
        /** Whether the corresponding column is nullable */
        public readonly bool    $nullable,
        /** PDO::PARAM_* constant string */
        public readonly string  $pdoParam,
        /** PHP native type, e.g. "int", "string", "?int" */
        public readonly string  $phpType,
        /**
         * When true this parameter is marked @optional: passing null skips
         * the filter condition entirely (SQL is rewritten at generation time).
         */
        public readonly bool    $optional = false,
        /**
         * When true this parameter appears inside an IN() clause:
         *   WHERE col IN (:param)
         * The method signature uses array $param and the SQL is rewritten
         * at runtime to replace :param with the appropriate number of ? placeholders.
         */
        public readonly bool    $inList   = false,
    ) {}
}
