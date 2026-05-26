<?php

declare(strict_types=1);

namespace SqlcPhp\TypeMapper;

use SqlcPhp\Config\TypeOverride;
use SqlcPhp\Generator\EnumGenerator;

/**
 * MySQL-specific type mapper.
 *
 * Maps MySQL column types to PHP types and PDO::PARAM_* constants,
 * applying type_overrides from sqlc.yaml first.
 */
class MySQLTypeMapper implements TypeMapperInterface
{
    /** @var TypeOverride[] */
    private array $overrides;

    /**
     * @param TypeOverride[]     $overrides From sqlc.yaml type_overrides
     * @param EnumGenerator|null $enumGen   When provided, ENUM columns map to backed enum classes
     */
    public function __construct(
        array             $overrides = [],
        private readonly ?EnumGenerator $enumGen = null,
    ) {
        $this->overrides = $overrides;
    }

    /**
     * Maps a MySQL column to a PHP type, consulting overrides first.
     *
     * @param string $sqlType    e.g. "ENUM" | "JSON" | "INT"
     * @param bool   $nullable
     * @param string $tableName  e.g. "users"
     * @param string $columnName e.g. "status"
     */
    public function toPhpType(
        string $sqlType,
        bool   $nullable,
        string $tableName  = '',
        string $columnName = '',
    ): string {
        // 1. Column-specific or db_type override (most specific — checked first)
        foreach ($this->overrides as $override) {
            if ($override->matches($tableName, $columnName, $sqlType)) {
                $effectiveNullable = $override->nullable ?? $nullable;
                $base = $override->phpType ?? $this->resolveBaseType($sqlType);
                return $effectiveNullable ? "?{$base}" : $base;
            }
        }

        // 2. ENUM → backed enum class name
        $upper = strtoupper(trim(preg_replace('/\(.*\)/s', '', $sqlType) ?? $sqlType));
        if ($upper === 'ENUM' && $this->enumGen !== null && $tableName !== '' && $columnName !== '') {
            $base = $this->enumGen->enumClassName($tableName, $columnName);
            return $nullable ? "?{$base}" : $base;
        }

        // 3. JSON → array
        if ($upper === 'JSON') {
            return $nullable ? '?array' : 'array';
        }

        // 4. Default mapping
        $base = $this->resolveBaseType($sqlType);
        return $nullable ? "?{$base}" : $base;
    }

    /**
     * Maps a MySQL column type to the appropriate PDO::PARAM_* constant string.
     */
    public function toPdoParam(
        string  $sqlType,
        ?string $tableName  = null,
        ?string $columnName = null,
    ): string {
        $upper = strtoupper($sqlType);

        if (str_contains($upper, 'INT') || $upper === 'TINYINT' || $upper === 'SMALLINT') {
            return 'PDO::PARAM_INT';
        }

        if ($upper === 'BOOLEAN' || $upper === 'BOOL') {
            return 'PDO::PARAM_BOOL';
        }

        return 'PDO::PARAM_STR';
    }

    /**
     * Generate the PHP fromRow cast expression for a given resolved PHP type.
     *
     * Handles all native types, \DateTimeImmutable, backed enums, arrays (JSON),
     * and nullable variants.
     */
    public function fromRowCast(string $phpType, string $alias, bool $nullable): string
    {
        $access = "\$row['{$alias}']";
        // Strip leading ? and \ to get the bare class/type name
        $base   = ltrim($phpType, '?\\');

        // DateTimeImmutable — date/datetime/timestamp columns
        if ($base === 'DateTimeImmutable') {
            return $nullable
                ? "isset({$access}) ? new \\DateTimeImmutable((string) {$access}) : null"
                : "new \\DateTimeImmutable((string) {$access})";
        }

        // Backed enum — ::from() for not-null, ::tryFrom() for nullable
        if ($this->isBackedEnum($base)) {
            return $nullable
                ? "isset({$access}) ? {$base}::tryFrom((string) {$access}) : null"
                : "{$base}::from((string) {$access})";
        }

        // JSON → array
        if ($base === 'array') {
            return $nullable
                ? "isset({$access}) ? json_decode((string) {$access}, true) : null"
                : "json_decode((string) {$access}, true) ?? []";
        }

        // Scalar types
        if ($nullable) {
            return match($base) {
                'int'   => "isset({$access}) ? (int) {$access} : null",
                'float' => "isset({$access}) ? (float) {$access} : null",
                'bool'  => "isset({$access}) ? (bool) {$access} : null",
                'mixed' => "{$access} ?? null",
                default => "{$access} ?? null",
            };
        }

        return match($base) {
            'int'   => "(int) {$access}",
            'float' => "(float) {$access}",
            'bool'  => "(bool) {$access}",
            'mixed' => "{$access}",
            default => "(string) {$access}",
        };
    }

    private function resolveBaseType(string $sqlType): string
    {
        $upper = strtoupper(trim(preg_replace('/\(.*\)/s', '', $sqlType) ?? $sqlType));

        return match(true) {
            in_array($upper, ['INT', 'INTEGER', 'BIGINT', 'MEDIUMINT']) => 'int',
            in_array($upper, ['TINYINT', 'SMALLINT'])                   => 'int',
            in_array($upper, ['FLOAT', 'DOUBLE', 'DECIMAL', 'NUMERIC']) => 'float',
            in_array($upper, ['BOOLEAN', 'BOOL'])                       => 'bool',
            // Date/time types → \DateTimeImmutable for full object support
            in_array($upper, ['DATE', 'DATETIME', 'TIMESTAMP'])         => '\DateTimeImmutable',
            // TIME stays as string — no standard PHP time-interval type
            default                                                     => 'string',
        };
    }

    /**
     * Determine if a bare class name refers to a backed enum generated by sqlc-php.
     * Backed enums are generated by EnumGenerator and always end in a known pattern
     * (e.g. OrderStatus, UserRole). We detect them by checking if the EnumGenerator
     * knows the class — we use a simpler heuristic: any non-primitive, non-DateTime
     * type that isn't array/mixed is treated as a backed enum for cast purposes.
     */
    private function isBackedEnum(string $base): bool
    {
        return !in_array($base, [
            'int', 'float', 'bool', 'string', 'array', 'mixed',
            'DateTimeImmutable', 'DateTime',
        ], true);
    }
}
