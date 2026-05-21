<?php

declare(strict_types=1);

namespace SqlcPhp\Tests\Config;

use PHPUnit\Framework\TestCase;
use SqlcPhp\Config\Config;
use SqlcPhp\Config\TypeOverride;

// =============================================================================
// TypeOverride
// =============================================================================

class TypeOverrideTest extends TestCase
{
    public function test_creates_from_column_key(): void
    {
        $override = TypeOverride::fromArray(['column' => 'users.active', 'php_type' => 'bool']);

        $this->assertSame('users.active', $override->column);
        $this->assertNull($override->dbType);
        $this->assertSame('bool', $override->phpType);
    }

    public function test_creates_from_db_type_key(): void
    {
        $override = TypeOverride::fromArray(['db_type' => 'tinyint', 'php_type' => 'bool']);

        $this->assertNull($override->column);
        $this->assertSame('TINYINT', $override->dbType); // uppercased
        $this->assertSame('bool', $override->phpType);
    }

    public function test_accepts_legacy_type_key(): void
    {
        $override = TypeOverride::fromArray(['column' => 'users.active', 'type' => 'bool']);
        $this->assertSame('bool', $override->phpType);
    }

    public function test_throws_when_php_type_missing(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        TypeOverride::fromArray(['column' => 'users.active']);
    }

    public function test_throws_when_neither_column_nor_db_type(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        TypeOverride::fromArray(['php_type' => 'bool']);
    }

    // -------------------------------------------------------------------------
    // matches()
    // -------------------------------------------------------------------------

    public function test_column_override_matches_exact_table_column(): void
    {
        $o = TypeOverride::fromArray(['column' => 'users.active', 'php_type' => 'bool']);
        $this->assertTrue($o->matches('users', 'active', 'TINYINT'));
    }

    public function test_column_override_is_case_insensitive(): void
    {
        $o = TypeOverride::fromArray(['column' => 'Users.Active', 'php_type' => 'bool']);
        $this->assertTrue($o->matches('users', 'active', 'TINYINT'));
    }

    public function test_column_override_does_not_match_different_column(): void
    {
        $o = TypeOverride::fromArray(['column' => 'users.active', 'php_type' => 'bool']);
        $this->assertFalse($o->matches('users', 'type_id', 'TINYINT'));
    }

    public function test_column_override_does_not_match_different_table(): void
    {
        $o = TypeOverride::fromArray(['column' => 'users.active', 'php_type' => 'bool']);
        $this->assertFalse($o->matches('roles', 'active', 'TINYINT'));
    }

    public function test_db_type_override_matches_any_column_with_that_type(): void
    {
        $o = TypeOverride::fromArray(['db_type' => 'TINYINT', 'php_type' => 'bool']);
        $this->assertTrue($o->matches('users', 'active', 'TINYINT'));
        $this->assertTrue($o->matches('orders', 'status', 'TINYINT'));
    }

    public function test_db_type_override_is_case_insensitive(): void
    {
        $o = TypeOverride::fromArray(['db_type' => 'tinyint', 'php_type' => 'bool']);
        $this->assertTrue($o->matches('users', 'active', 'TINYINT'));
    }

    public function test_db_type_override_does_not_match_different_type(): void
    {
        $o = TypeOverride::fromArray(['db_type' => 'TINYINT', 'php_type' => 'bool']);
        $this->assertFalse($o->matches('users', 'role_id', 'SMALLINT'));
    }

    public function test_db_type_strips_display_width_for_matching(): void
    {
        $o = TypeOverride::fromArray(['db_type' => 'TINYINT', 'php_type' => 'bool']);
        // Schema might store TINYINT(1) — the override should still match
        $this->assertTrue($o->matches('users', 'active', 'TINYINT(1)'));
    }
}

// =============================================================================
// Config
// =============================================================================

class ConfigTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/sqlc-php-test-' . uniqid();
        mkdir($this->tmpDir);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->tmpDir . '/*') ?: []);
        rmdir($this->tmpDir);
    }

    private function writeConfig(string $yaml): string
    {
        $path = $this->tmpDir . '/sqlc.yaml';
        file_put_contents($path, $yaml);
        return $path;
    }

    public function test_loads_basic_config(): void
    {
        $path = $this->writeConfig(<<<YAML
            version: "1"
            schema: schema.sql
            queries: queries.sql
            php:
              namespace: "App\\Database"
              out: generated
              engine: mysql
            YAML);

        $config = Config::fromFile($path);

        $this->assertSame('1',              $config->version);
        $this->assertSame('schema.sql',     $config->schema);
        $this->assertSame('queries.sql',    $config->queries);
        $this->assertSame('App\\Database',  $config->namespace);
        $this->assertSame('generated',      $config->out);
        $this->assertSame('mysql',          $config->engine);
    }

    public function test_defaults_are_applied_when_keys_missing(): void
    {
        $path = $this->writeConfig("version: \"1\"\nschema: s.sql\nqueries: q.sql\n");
        $config = Config::fromFile($path);

        $this->assertSame('App\\Database', $config->namespace);
        $this->assertSame('generated',     $config->out);
        $this->assertSame('mysql',         $config->engine);
    }

    public function test_throws_when_file_missing(): void
    {
        $this->expectException(\RuntimeException::class);
        Config::fromFile('/nonexistent/path/sqlc.yaml');
    }

    public function test_parses_column_type_override(): void
    {
        $path = $this->writeConfig(<<<YAML
            version: "1"
            schema: s.sql
            queries: q.sql
            type_overrides:
              - column: "users.active"
                php_type: "bool"
            YAML);

        $config = Config::fromFile($path);

        $this->assertCount(1, $config->typeOverrides);
        $this->assertSame('users.active', $config->typeOverrides[0]->column);
        $this->assertSame('bool',         $config->typeOverrides[0]->phpType);
    }

    public function test_parses_db_type_override(): void
    {
        $path = $this->writeConfig(<<<YAML
            version: "1"
            schema: s.sql
            queries: q.sql
            type_overrides:
              - db_type: "TINYINT"
                php_type: "bool"
            YAML);

        $config = Config::fromFile($path);

        $this->assertCount(1, $config->typeOverrides);
        $this->assertSame('TINYINT', $config->typeOverrides[0]->dbType);
    }

    public function test_parses_multiple_overrides(): void
    {
        $path = $this->writeConfig(<<<YAML
            version: "1"
            schema: s.sql
            queries: q.sql
            type_overrides:
              - column: "users.active"
                php_type: "bool"
              - db_type: "TIMESTAMP"
                php_type: "\\DateTimeImmutable"
            YAML);

        $config = Config::fromFile($path);
        $this->assertCount(2, $config->typeOverrides);
    }

    public function test_no_overrides_returns_empty_array(): void
    {
        $path = $this->writeConfig("version: \"1\"\nschema: s.sql\nqueries: q.sql\n");
        $config = Config::fromFile($path);
        $this->assertSame([], $config->typeOverrides);
    }
}
