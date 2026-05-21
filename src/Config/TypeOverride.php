<?php

declare(strict_types=1);

namespace SqlcPhp\Config;

/**
 * A single type override entry from sqlc.yaml.
 *
 * Two mutually exclusive match modes:
 *   - column  : targets one specific column,  e.g. "users.metadata"
 *   - db_type : targets every column whose SQL type matches, e.g. "TINYINT(1)"
 *
 * `phpType` is the PHP type string to use instead of the default mapping,
 * e.g. "bool", "array", "\DateTimeImmutable".
 */
readonly class TypeOverride
{
    private function __construct(
        /** Fully-qualified column reference "table.column", or null */
        public ?string $column,
        /** SQL / DB type string to match (case-insensitive), or null */
        public ?string $dbType,
        /** Target PHP type string */
        public string  $phpType,
    ) {}

    /**
     * @param array<string, string> $data
     * @throws \InvalidArgumentException
     */
    public static function fromArray(array $data): self
    {
        $phpType = trim($data['php_type'] ?? $data['type'] ?? '');

        if ($phpType === '') {
            throw new \InvalidArgumentException(
                "type_override entry is missing 'php_type' (or 'type') key."
            );
        }

        $column = isset($data['column']) ? trim($data['column']) : null;
        $dbType = isset($data['db_type']) ? strtoupper(trim($data['db_type'])) : null;

        if ($column === null && $dbType === null) {
            throw new \InvalidArgumentException(
                "type_override entry must specify either 'column' or 'db_type'."
            );
        }

        return new self(
            column:  $column  ?: null,
            dbType:  $dbType  ?: null,
            phpType: $phpType,
        );
    }

    /**
     * Returns true when this override applies to the given table+column+sqlType combination.
     */
    public function matches(string $tableName, string $columnName, string $sqlType): bool
    {
        if ($this->column !== null) {
            // "users.metadata" — case-insensitive exact match
            return strcasecmp($this->column, "{$tableName}.{$columnName}") === 0;
        }

        if ($this->dbType !== null) {
            // Match the raw SQL type with and without display width
            $stripped = strtoupper(preg_replace('/\(.*\)/', '', $sqlType) ?? $sqlType);
            return $stripped === $this->dbType
                || strtoupper($sqlType) === $this->dbType;
        }

        return false;
    }
}
