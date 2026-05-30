<?php

declare(strict_types=1);

namespace SqlcPhp\SchemaExtractor;

/**
 * Creates the appropriate SchemaExtractor based on the engine name.
 */
class SchemaExtractorFactory
{
    public static function create(string $engine): SchemaExtractorInterface
    {
        return match (strtolower($engine)) {
            'mysql', 'mariadb' => new MySQLSchemaExtractor(),
            default => throw new \RuntimeException(
                "Schema extraction is not yet supported for engine '{$engine}'. " .
                "Currently supported: mysql, mariadb."
            ),
        };
    }

    /**
     * Detect the engine from a PDO DSN string.
     * e.g. "mysql:host=localhost" → "mysql"
     */
    public static function engineFromDsn(string $dsn): string
    {
        $driver = strtolower(explode(':', $dsn, 2)[0] ?? '');

        return match ($driver) {
            'mysql'  => 'mysql',
            'pgsql'  => 'postgres',
            'sqlite' => 'sqlite',
            default  => $driver,
        };
    }
}
