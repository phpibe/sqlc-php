<?php

declare(strict_types=1);

namespace SqlcPhp\TypeMapper;

/**
 * Contract for SQL-type → PHP-type mapping.
 *
 * Each database engine provides its own implementation:
 *   - MySQLTypeMapper      (engine: mysql)
 *   - PostgreSQLTypeMapper (engine: postgres) — v1.7.0
 *
 * All consumers depend on this interface, not on a concrete mapper class.
 * The correct implementation is resolved by TypeMapperFactory based on
 * the `engine` field in sqlc.yaml.
 */
interface TypeMapperInterface
{
    /**
     * Return the fully-qualified class name for types that are classes (enums,
     * DateTimeImmutable, etc.), or null for scalars (int, string, float, bool, array).
     *
     * Used by ExtensionGenerator to produce correct `use` statements in scaffolds.
     */
    public function toPhpFqcn(
        string $sqlType,
        string $tableName  = '',
        string $columnName = '',
    ): ?string;

    /**
     * Map a SQL column type to a PHP native type string.
     *
     * @param string $sqlType     SQL column type, e.g. "INT", "VARCHAR", "JSONB"
     * @param bool   $nullable    Whether the column is nullable
     * @param string $tableName   Table name — used for column-specific overrides
     * @param string $columnName  Column name — used for column-specific overrides
     *
     * @return string PHP type string, e.g. "int", "?string", "array", "OrderStatus"
     */
    public function toPhpType(
        string $sqlType,
        bool   $nullable,
        string $tableName  = '',
        string $columnName = '',
    ): string;

    /**
     * Map a SQL column type to the appropriate PDO::PARAM_* constant string.
     *
     * @param string       $sqlType    SQL column type
     * @param string|null  $tableName  Optional — used for column-specific overrides
     * @param string|null  $columnName Optional — used for column-specific overrides
     *
     * @return string e.g. "PDO::PARAM_INT", "PDO::PARAM_STR"
     */
    public function toPdoParam(
        string  $sqlType,
        ?string $tableName  = null,
        ?string $columnName = null,
    ): string;

    /**
     * Generate the PHP expression that hydrates a single column value from a
     * PDO associative-array row inside a `fromRow(array $row): self` method.
     *
     * The expression must be a valid PHP rvalue, e.g.:
     *   "(int) $row['id']"
     *   "new \DateTimeImmutable((string) $row['created_at'])"
     *   "isset($row['deleted_at']) ? new \DateTimeImmutable((string) $row['deleted_at']) : null"
     *   "json_decode((string) $row['tags'], true) ?? []"
     *
     * @param string $phpType  The resolved PHP type, e.g. "int", "?string",
     *                         "\DateTimeImmutable", "?\DateTimeImmutable"
     * @param string $alias    The column alias (key in the $row array)
     * @param bool   $nullable Whether the value can be null
     */
    public function fromRowCast(string $phpType, string $alias, bool $nullable): string;
}
