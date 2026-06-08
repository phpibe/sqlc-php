<?php

declare(strict_types=1);

namespace SqlcPhp\Catalog;

use SqlcPhp\Parser\TableDefinition;
use SqlcPhp\Parser\ColumnDefinition;

/**
 * Holds all parsed table definitions and provides lookup utilities.
 */
class SchemaCatalog
{
    /** @var array<string, TableDefinition> */
    private array $tables = [];

    /**
     * @param TableDefinition[] $tables
     */
    public function __construct(array $tables)
    {
        foreach ($tables as $table) {
            $this->tables[strtolower($table->name)] = $table;
        }
    }

    public function getTable(string $name): ?TableDefinition
    {
        return $this->tables[strtolower($name)] ?? null;
    }

    /**
     * Returns all columns for the given table, or empty array if not found.
     *
     * @return ColumnDefinition[]
     */
    public function getColumns(string $tableName): array
    {
        return $this->getTable($tableName)?->columns ?? [];
    }

    /** @return TableDefinition[] */
    public function all(): array
    {
        return array_values($this->tables);
    }

    /**
     * Returns the name of the primary key column for the given table.
     *
     * Detection strategy (in order):
     *   1. Column with isPrimaryKey = true  (inline PRIMARY KEY declaration)
     *   2. Column with autoIncrement = true (AUTO_INCREMENT implies PK in MySQL)
     *   3. Column named 'id' (common convention fallback)
     *
     * Returns null if none match — caller should emit a clear error.
     */
    public function primaryKey(string $tableName): ?string
    {
        $columns = $this->getColumns($tableName);

        foreach ($columns as $col) {
            if ($col->isPrimaryKey) return $col->name;
        }
        foreach ($columns as $col) {
            if ($col->autoIncrement) return $col->name;
        }
        foreach ($columns as $col) {
            if (strtolower($col->name) === 'id') return $col->name;
        }

        return null;
    }
}
