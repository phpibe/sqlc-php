<?php

declare(strict_types=1);

namespace SqlcPhp\Tests\TypeMapper;

use PHPUnit\Framework\TestCase;
use SqlcPhp\Generator\EnumGenerator;
use SqlcPhp\TypeMapper\MySQLTypeMapper;
use SqlcPhp\TypeMapper\TypeMapperFactory;
use SqlcPhp\TypeMapper\TypeMapperInterface;

class TypeMapperFactoryTest extends TestCase
{
    // =========================================================================
    // TypeMapperInterface contract
    // =========================================================================

    public function test_mysql_mapper_implements_interface(): void
    {
        $mapper = new MySQLTypeMapper();
        $this->assertInstanceOf(TypeMapperInterface::class, $mapper);
    }

    // =========================================================================
    // TypeMapperFactory — supported engines
    // =========================================================================

    public function test_factory_creates_mysql_mapper_for_mysql_engine(): void
    {
        $mapper = TypeMapperFactory::create('mysql');
        $this->assertInstanceOf(TypeMapperInterface::class, $mapper);
        $this->assertInstanceOf(MySQLTypeMapper::class, $mapper);
    }

    public function test_factory_engine_is_case_insensitive(): void
    {
        $this->assertInstanceOf(TypeMapperInterface::class, TypeMapperFactory::create('MYSQL'));
        $this->assertInstanceOf(TypeMapperInterface::class, TypeMapperFactory::create('MySQL'));
    }

    public function test_factory_passes_overrides_to_mapper(): void
    {
        $override = \SqlcPhp\Config\TypeOverride::fromArray([
            'db_type'  => 'TINYINT',
            'php_type' => 'bool',
        ]);
        $mapper = TypeMapperFactory::create('mysql', [$override]);

        $this->assertSame('bool', $mapper->toPhpType('TINYINT', false));
    }

    public function test_factory_passes_enum_generator_to_mapper(): void
    {
        $enumGen = new EnumGenerator('App');
        $mapper  = TypeMapperFactory::create('mysql', [], $enumGen);

        // ENUM column should resolve to backed enum class name
        $result = $mapper->toPhpType('ENUM', false, 'orders', 'status');
        $this->assertSame('OrderStatus', $result);
    }

    // =========================================================================
    // TypeMapperFactory — unsupported engines
    // =========================================================================

    public function test_factory_throws_for_postgres_with_helpful_message(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/postgres.*not yet supported/i');

        TypeMapperFactory::create('postgres');
    }

    public function test_factory_throws_for_postgresql_alias(): void
    {
        $this->expectException(\RuntimeException::class);
        TypeMapperFactory::create('postgresql');
    }

    public function test_factory_throws_for_pgsql_alias(): void
    {
        $this->expectException(\RuntimeException::class);
        TypeMapperFactory::create('pgsql');
    }

    public function test_factory_error_mentions_planned_version_for_postgres(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/v1\.7\.0/');

        TypeMapperFactory::create('postgres');
    }

    public function test_factory_throws_for_unknown_engine(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/Unsupported engine/');

        TypeMapperFactory::create('sqlite');
    }

    public function test_factory_error_message_contains_engine_name(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/sqlite/');

        TypeMapperFactory::create('sqlite');
    }

    public function test_factory_error_message_lists_supported_engines(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/mysql/');

        TypeMapperFactory::create('oracle');
    }

    public function test_supported_engines_constant_contains_mysql(): void
    {
        $this->assertContains('mysql', TypeMapperFactory::SUPPORTED_ENGINES);
    }

    // =========================================================================
    // Interface contract — toPdoParam signature
    // =========================================================================

    public function test_to_pdo_param_accepts_optional_table_and_column(): void
    {
        $mapper = TypeMapperFactory::create('mysql');

        // Must not throw when called with or without optional args
        $this->assertSame('PDO::PARAM_INT', $mapper->toPdoParam('INT'));
        $this->assertSame('PDO::PARAM_INT', $mapper->toPdoParam('INT', 'users', 'id'));
        $this->assertSame('PDO::PARAM_STR', $mapper->toPdoParam('VARCHAR'));
        $this->assertSame('PDO::PARAM_STR', $mapper->toPdoParam('VARCHAR', null, null));
    }

    // =========================================================================
    // Config engine validation — integration
    // =========================================================================

    public function test_config_engine_mysql_is_accepted_by_factory(): void
    {
        $tmpDir = sys_get_temp_dir() . '/sqlc-engine-' . uniqid();
        mkdir($tmpDir);
        file_put_contents($tmpDir . '/sqlc.yaml',
            "version: \"2\"\nschema: s.sql\nengine: mysql\ntargets:\n  - namespace: \"App\"\n    out: gen\n    queries: q.sql\n"
        );

        $config = \SqlcPhp\Config\Config::fromFile($tmpDir . '/sqlc.yaml');
        $mapper = TypeMapperFactory::create($config->engine);

        $this->assertInstanceOf(TypeMapperInterface::class, $mapper);

        array_map('unlink', glob($tmpDir . '/*') ?: []);
        rmdir($tmpDir);
    }

    public function test_config_engine_postgres_throws_at_factory(): void
    {
        $tmpDir = sys_get_temp_dir() . '/sqlc-engine-' . uniqid();
        mkdir($tmpDir);
        file_put_contents($tmpDir . '/sqlc.yaml',
            "version: \"2\"\nschema: s.sql\nengine: postgres\ntargets:\n  - namespace: \"App\"\n    out: gen\n    queries: q.sql\n"
        );

        $config = \SqlcPhp\Config\Config::fromFile($tmpDir . '/sqlc.yaml');

        $this->expectException(\RuntimeException::class);
        TypeMapperFactory::create($config->engine);

        array_map('unlink', glob($tmpDir . '/*') ?: []);
        rmdir($tmpDir);
    }
}
