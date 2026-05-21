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
}
