<?php

declare(strict_types=1);

namespace SqlcPhp\Config;

/**
 * A single type override entry from sqlc.yaml.
 *
 * Two mutually exclusive match modes:
 *   - column  : targets one specific column,  e.g. "users.metadata"
 *   - db_type : targets every column whose SQL type matches, e.g. "TINYINT"
 *
 * Optional fields:
 *   - php_type : the PHP type to use instead of the default mapping
 *   - nullable : force the nullability of the property regardless of the schema
 *                true  → always ?type
 *                false → always type (never nullable)
 *                null  → inherit nullability from the schema column (default)
 */
readonly class TypeOverride
{
    private function __construct(
        /** Fully-qualified column reference "table.column", or null */
        public ?string $column,
        /** SQL / DB type string to match (case-insensitive), or null */
        public ?string $dbType,
        /** Target PHP type string, or null to keep the default mapping */
        public ?string $phpType,
        /**
         * Nullable override:
         *   true  → force ?type regardless of schema
         *   false → force non-nullable type regardless of schema
         *   null  → inherit from schema
         */
        public ?bool   $nullable,
    ) {}

    /**
     * @param array<string, mixed> $data
     * @throws \InvalidArgumentException
     */
    public static function fromArray(array $data): self
    {
        $phpType = isset($data['php_type']) ? trim((string) $data['php_type']) : null;
        // Legacy 'type' key alias
        if ($phpType === null && isset($data['type'])) {
            $phpType = trim((string) $data['type']);
        }
        if ($phpType === '') $phpType = null;

        $column = isset($data['column']) ? trim((string) $data['column']) : null;
        $dbType = isset($data['db_type']) ? strtoupper(trim((string) $data['db_type'])) : null;

        if ($column === null && $dbType === null) {
            throw new \InvalidArgumentException(
                "type_override entry must specify either 'column' or 'db_type'."
            );
        }

        if ($phpType === null && !isset($data['nullable'])) {
            throw new \InvalidArgumentException(
                "type_override entry is missing 'php_type' (or 'type') key."
            );
        }

        // Parse nullable: true/false/yes/no/1/0
        $nullable = null;
        if (isset($data['nullable']) && $data['nullable'] !== '') {
            $nullable = filter_var($data['nullable'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        }

        return new self(
            column:   $column  ?: null,
            dbType:   $dbType  ?: null,
            phpType:  $phpType,
            nullable: $nullable,
        );
    }

    /**
     * Returns true when this override applies to the given table+column+sqlType combination.
     */
    public function matches(string $tableName, string $columnName, string $sqlType): bool
    {
        if ($this->column !== null) {
            return strcasecmp($this->column, "{$tableName}.{$columnName}") === 0;
        }

        if ($this->dbType !== null) {
            $stripped = strtoupper(preg_replace('/\(.*\)/', '', $sqlType) ?? $sqlType);
            return $stripped === $this->dbType
                || strtoupper($sqlType) === $this->dbType;
        }

        return false;
    }
}
