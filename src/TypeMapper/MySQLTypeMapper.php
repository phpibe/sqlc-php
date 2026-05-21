<?php

declare(strict_types=1);

namespace SqlcPhp\TypeMapper;

use SqlcPhp\Config\TypeOverride;

class MySQLTypeMapper
{
    /** @var TypeOverride[] */
    private array $overrides;

    /**
     * @param TypeOverride[] $overrides  From sqlc.yaml type_overrides
     */
    public function __construct(array $overrides = [])
    {
        $this->overrides = $overrides;
    }

    /**
     * Maps a column to a PHP type, consulting overrides first.
     *
     * @param string $tableName   e.g. "users"
     * @param string $columnName  e.g. "metadata"
     * @param string $sqlType     e.g. "JSON"
     * @param bool   $nullable
     */
    public function toPhpType(
        string $sqlType,
        bool   $nullable,
        string $tableName  = '',
        string $columnName = '',
    ): string {
        // 1. Column-specific override (most specific — checked first)
        // 2. DB-type override
        foreach ($this->overrides as $override) {
            if ($override->matches($tableName, $columnName, $sqlType)) {
                $base = $override->phpType;
                return $nullable ? "?{$base}" : $base;
            }
        }

        // 3. Default mapping
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
        $upper = strtoupper(trim(preg_replace('/\(.*\)/', '', $sqlType) ?? $sqlType));

        return match(true) {
            in_array($upper, ['INT', 'INTEGER', 'BIGINT', 'MEDIUMINT']) => 'int',
            in_array($upper, ['TINYINT', 'SMALLINT'])                   => 'int',
            in_array($upper, ['FLOAT', 'DOUBLE', 'DECIMAL', 'NUMERIC']) => 'float',
            in_array($upper, ['BOOLEAN', 'BOOL'])                       => 'bool',
            in_array($upper, ['DATE', 'DATETIME', 'TIMESTAMP', 'TIME']) => 'string',
            in_array($upper, ['JSON'])                                  => 'string',
            default                                                     => 'string',
        };
    }
}
