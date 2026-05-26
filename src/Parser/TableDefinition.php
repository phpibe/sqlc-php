<?php

declare(strict_types=1);

namespace SqlcPhp\Parser;

/**
 * Represents a parsed table with all its columns.
 * Used for both real schema tables and virtual tables declared in config.
 */
class TableDefinition
{
    /** @param ColumnDefinition[] $columns */
    public function __construct(
        public readonly string $name,
        public readonly array  $columns,
        /**
         * When true this table was declared as a virtual_table in sqlc.yaml
         * (e.g. a database view, materialized view, or external table).
         *
         * Virtual tables participate in the SchemaCatalog for column type
         * resolution, but no Model class is generated for them — they have
         * no corresponding CREATE TABLE in the schema.
         */
        public readonly bool   $virtual = false,
    ) {}
}
