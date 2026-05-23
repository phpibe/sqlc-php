<?php

declare(strict_types=1);

namespace SqlcPhp\TypeMapper;

use SqlcPhp\Config\TypeOverride;
use SqlcPhp\Generator\EnumGenerator;

class MySQLTypeMapper
{
    /** @var TypeOverride[] */
    private array $overrides;

    /**
     * @param TypeOverride[]   $overrides  From sqlc.yaml type_overrides
     * @param EnumGenerator|null $enumGen  When provided, ENUM columns map to backed enum classes
     */
    public function __construct(
        array            $overrides = [],
        private readonly ?EnumGenerator $enumGen = null,
    ) {
        $this->overrides = $overrides;
    }

    /**
     * Maps a column to a PHP type, consulting overrides first.
     *
     * @param string $tableName   e.g. "users"
     * @param string $columnName  e.g. "status"
     * @param string $sqlType     e.g. "ENUM" | "JSON" | "INT"
     * @param bool   $nullable
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
                $base = $override->phpType;
                return $nullable ? "?{$base}" : $base;
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
     * Overrides do not affect PDO binding — we bind by the original SQL type.
     */
    public function toPdoParam(string $sqlType): string
    {
        $upper = strtoupper($sqlType);

        if (str_contains($upper, 'INT') || $upper === 'TINYINT' || $upper === 'SMALLINT') {
            return 'PDO::PARAM_INT';
        }

        if ($upper === 'BOOLEAN' || $upper === 'BOOL') {
            return 'PDO::PARAM_BOOL';
        }

        return 'PDO::PARAM_STR';
    }

    private function resolveBaseType(string $sqlType): string
    {
        $upper = strtoupper(trim(preg_replace('/\(.*\)/s', '', $sqlType) ?? $sqlType));

        return match(true) {
            in_array($upper, ['INT', 'INTEGER', 'BIGINT', 'MEDIUMINT']) => 'int',
            in_array($upper, ['TINYINT', 'SMALLINT'])                   => 'int',
            in_array($upper, ['FLOAT', 'DOUBLE', 'DECIMAL', 'NUMERIC']) => 'float',
            in_array($upper, ['BOOLEAN', 'BOOL'])                       => 'bool',
            in_array($upper, ['DATE', 'DATETIME', 'TIMESTAMP', 'TIME']) => 'string',
            default                                                     => 'string',
        };
    }
}
