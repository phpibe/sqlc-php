<?php

declare(strict_types=1);

namespace SqlcPhp\TypeMapper;

use SqlcPhp\Config\TypeOverride;
use SqlcPhp\Generator\EnumGenerator;

/**
 * Resolves the correct TypeMapperInterface implementation
 * based on the `engine` field from sqlc.yaml.
 *
 * Current support:
 *   mysql                    → MySQLTypeMapper
 *   postgres | postgresql    → (planned: PostgreSQLTypeMapper — v1.7.0)
 *
 * Adding a new engine:
 *   1. Create src/TypeMapper/YourEngineTypeMapper.php implementing TypeMapperInterface
 *   2. Add a case to the match() expression below
 *   3. Register the engine name in the $supported list
 */
class TypeMapperFactory
{
    /**
     * @internal Exposed for documentation and error messages only.
     * @var string[]
     */
    public const SUPPORTED_ENGINES = ['mysql'];

    /**
     * @param string         $engine    From Config::$engine (e.g. "mysql", "postgres")
     * @param TypeOverride[] $overrides From Config::$typeOverrides
     * @param EnumGenerator|null $enumGen   For ENUM column mapping
     *
     * @throws \RuntimeException when the engine is not supported
     */
    public static function create(
        string        $engine,
        array         $overrides = [],
        ?EnumGenerator $enumGen  = null,
    ): TypeMapperInterface {
        return match (strtolower(trim($engine))) {
            'mysql'  => new MySQLTypeMapper($overrides, $enumGen),

            'postgres',
            'postgresql',
            'pgsql'  => throw new \RuntimeException(
                "Engine 'postgres' is not yet supported. " .
                "PostgreSQL support is planned for v1.7.0. " .
                "Currently supported engines: " . implode(', ', self::SUPPORTED_ENGINES) . "."
            ),

            default  => throw new \RuntimeException(
                "Unsupported engine '" . htmlspecialchars($engine, ENT_QUOTES) . "'. " .
                "Supported engines: " . implode(', ', self::SUPPORTED_ENGINES) . "."
            ),
        };
    }
}
