<?php

declare(strict_types=1);

namespace SqlcPhp\Parser;

/**
 * Represents a single column parsed from a CREATE TABLE statement
 * or declared in virtual_tables: config.
 */
class ColumnDefinition
{
    public function __construct(
        public readonly string  $name,
        public readonly string  $sqlType,
        public readonly bool    $nullable,
        public readonly bool    $autoIncrement,
        public readonly ?string $default,
        /** Non-empty only when sqlType === 'ENUM', contains the raw quoted values. */
        public readonly array   $enumValues    = [],
        /** True when this column is declared with PRIMARY KEY in its column definition. */
        public readonly bool    $isPrimaryKey  = false,
    ) {}

    /** Returns true when this column is a MySQL ENUM. */
    public function isEnum(): bool
    {
        return $this->sqlType === 'ENUM' && !empty($this->enumValues);
    }
}
