<?php

declare(strict_types=1);

namespace SqlcPhp\Resolver;

/**
 * A single column in the SELECT result set, fully resolved against the schema.
 */
class ResolvedColumn
{
    public function __construct(
        /** Output alias or column name, used as PHP property name */
        public readonly string  $alias,
        /** Original column name in the table */
        public readonly string  $columnName,
        /** Source table name */
        public readonly string  $tableName,
        /** SQL type from the schema */
        public readonly string  $sqlType,
        /** Whether nullable */
        public readonly bool    $nullable,
        /** PHP type string */
        public readonly string  $phpType,
    ) {}
}
