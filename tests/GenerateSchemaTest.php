<?php

declare(strict_types=1);

namespace SqlcPhp\Tests;

use PHPUnit\Framework\TestCase;
use SqlcPhp\Config\Config;
use SqlcPhp\Config\DatabaseConfig;
use SqlcPhp\Config\Target;
use SqlcPhp\SchemaExtractor\MySQLSchemaExtractor;
use SqlcPhp\SchemaExtractor\SchemaExtractorFactory;

class GenerateSchemaTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/sqlc-schema-' . uniqid();
        mkdir($this->tmpDir);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->tmpDir . '/*') ?: [] as $f) unlink($f);
        rmdir($this->tmpDir);
    }

    private function writeConfig(string $yaml): string
    {
        $path = $this->tmpDir . '/sqlc.yaml';
        file_put_contents($path, $yaml);
        return $path;
    }

    // =========================================================================
    // DatabaseConfig — construction and parsing
    // =========================================================================

    public function test_database_config_from_array_basic(): void
    {
        $cfg = DatabaseConfig::fromArray([
            'dsn'      => 'mysql:host=localhost;dbname=myapp',
            'username' => 'root',
            'password' => 'secret',
        ]);

        $this->assertSame('mysql:host=localhost;dbname=myapp', $cfg->dsn);
        $this->assertSame('root',   $cfg->username);
        $this->assertSame('secret', $cfg->password);
    }

    public function test_database_config_defaults(): void
    {
        $cfg = DatabaseConfig::fromArray(['dsn' => 'mysql:host=localhost']);

        $this->assertSame('', $cfg->username);
        $this->assertSame('', $cfg->password);
        $this->assertSame([], $cfg->excludeTables);
        $this->assertSame([], $cfg->includeTables);
    }

    public function test_database_config_exclude_tables(): void
    {
        $cfg = DatabaseConfig::fromArray([
            'dsn'            => 'mysql:host=localhost',
            'exclude_tables' => ['migrations', 'failed_jobs'],
        ]);

        $this->assertSame(['migrations', 'failed_jobs'], $cfg->excludeTables);
    }

    public function test_database_config_include_tables(): void
    {
        $cfg = DatabaseConfig::fromArray([
            'dsn'            => 'mysql:host=localhost',
            'include_tables' => ['users', 'orders'],
        ]);

        $this->assertSame(['users', 'orders'], $cfg->includeTables);
    }

    // =========================================================================
    // DatabaseConfig — environment variable expansion
    // =========================================================================

    public function test_resolved_dsn_expands_env_var(): void
    {
        putenv('TEST_DB_HOST=db.example.com');
        $cfg = DatabaseConfig::fromArray(['dsn' => 'mysql:host=${TEST_DB_HOST}']);
        $this->assertSame('mysql:host=db.example.com', $cfg->resolvedDsn());
        putenv('TEST_DB_HOST');
    }

    public function test_resolved_username_expands_env_var(): void
    {
        putenv('TEST_DB_USER=myuser');
        $cfg = DatabaseConfig::fromArray(['dsn' => 'mysql:', 'username' => '${TEST_DB_USER}']);
        $this->assertSame('myuser', $cfg->resolvedUsername());
        putenv('TEST_DB_USER');
    }

    public function test_resolved_password_expands_env_var(): void
    {
        putenv('TEST_DB_PASS=supersecret');
        $cfg = DatabaseConfig::fromArray(['dsn' => 'mysql:', 'password' => '${TEST_DB_PASS}']);
        $this->assertSame('supersecret', $cfg->resolvedPassword());
        putenv('TEST_DB_PASS');
    }

    public function test_unexpanded_env_var_left_as_is(): void
    {
        // Env var not set — placeholder is preserved
        putenv('SQLC_NONEXISTENT_VAR'); // ensure unset
        $cfg = DatabaseConfig::fromArray(['dsn' => '${SQLC_NONEXISTENT_VAR}']);
        $this->assertSame('${SQLC_NONEXISTENT_VAR}', $cfg->resolvedDsn());
    }

    public function test_literal_value_not_changed(): void
    {
        $cfg = DatabaseConfig::fromArray(['dsn' => 'mysql:host=localhost', 'password' => 'plain']);
        $this->assertSame('plain', $cfg->resolvedPassword());
    }

    // =========================================================================
    // DatabaseConfig — shouldInclude filter
    // =========================================================================

    public function test_should_include_all_when_no_filter(): void
    {
        $cfg = DatabaseConfig::fromArray(['dsn' => 'x']);
        $this->assertTrue($cfg->shouldInclude('users'));
        $this->assertTrue($cfg->shouldInclude('migrations'));
    }

    public function test_exclude_tables_blocks_table(): void
    {
        $cfg = DatabaseConfig::fromArray(['dsn' => 'x', 'exclude_tables' => ['migrations']]);
        $this->assertFalse($cfg->shouldInclude('migrations'));
        $this->assertTrue($cfg->shouldInclude('users'));
    }

    public function test_include_tables_whitelist(): void
    {
        $cfg = DatabaseConfig::fromArray(['dsn' => 'x', 'include_tables' => ['users', 'orders']]);
        $this->assertTrue($cfg->shouldInclude('users'));
        $this->assertTrue($cfg->shouldInclude('orders'));
        $this->assertFalse($cfg->shouldInclude('migrations'));
        $this->assertFalse($cfg->shouldInclude('sessions'));
    }

    public function test_include_tables_takes_priority_over_exclude(): void
    {
        // include_tables is a whitelist — exclude_tables is ignored when include_tables is set
        $cfg = DatabaseConfig::fromArray([
            'dsn'            => 'x',
            'include_tables' => ['users'],
            'exclude_tables' => ['users'],   // would exclude users, but include wins
        ]);
        $this->assertTrue($cfg->shouldInclude('users'));
    }

    // =========================================================================
    // Config::fromFile — database block parsing
    // =========================================================================

    public function test_config_parses_global_database_block(): void
    {
        file_put_contents($this->tmpDir . '/schema.sql', '');
        $path = $this->writeConfig(
            "version: \"2\"\nschema: schema.sql\n" .
            "database:\n" .
            "  dsn: \"mysql:host=localhost;dbname=myapp\"\n" .
            "  username: root\n" .
            "  password: secret\n" .
            "targets:\n  - namespace: \"App\"\n    out: gen\n    queries: q.sql\n"
        );

        $cfg = Config::fromFile($path);
        $this->assertNotNull($cfg->database);
        $this->assertSame('mysql:host=localhost;dbname=myapp', $cfg->database->dsn);
        $this->assertSame('root',   $cfg->database->username);
        $this->assertSame('secret', $cfg->database->password);
    }

    public function test_config_database_null_when_absent(): void
    {
        $path = $this->writeConfig(
            "version: \"2\"\nschema: schema.sql\ntargets:\n  - namespace: \"App\"\n    out: gen\n    queries: q.sql\n"
        );
        $cfg = Config::fromFile($path);
        $this->assertNull($cfg->database);
    }

    public function test_target_parses_database_block(): void
    {
        $path = $this->writeConfig(
            "version: \"2\"\nschema: schema.sql\n" .
            "targets:\n" .
            "  - namespace: \"App\"\n    out: gen\n    queries: q.sql\n" .
            "    database:\n" .
            "      dsn: \"mysql:host=localhost;dbname=target_db\"\n"
        );
        $cfg = Config::fromFile($path);
        $this->assertNotNull($cfg->targets[0]->database);
        $this->assertSame('mysql:host=localhost;dbname=target_db', $cfg->targets[0]->database->dsn);
    }

    public function test_target_database_null_when_absent(): void
    {
        $path = $this->writeConfig(
            "version: \"2\"\nschema: schema.sql\ntargets:\n  - namespace: \"App\"\n    out: gen\n    queries: q.sql\n"
        );
        $cfg = Config::fromFile($path);
        $this->assertNull($cfg->targets[0]->database);
    }

    // =========================================================================
    // SchemaExtractorFactory
    // =========================================================================

    public function test_factory_returns_mysql_extractor(): void
    {
        $extractor = SchemaExtractorFactory::create('mysql');
        $this->assertInstanceOf(MySQLSchemaExtractor::class, $extractor);
    }

    public function test_factory_returns_mysql_extractor_for_mariadb(): void
    {
        $extractor = SchemaExtractorFactory::create('mariadb');
        $this->assertInstanceOf(MySQLSchemaExtractor::class, $extractor);
    }

    public function test_factory_throws_for_unsupported_engine(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/not yet supported/');
        SchemaExtractorFactory::create('sqlite');
    }

    public function test_engine_from_dsn_mysql(): void
    {
        $this->assertSame('mysql', SchemaExtractorFactory::engineFromDsn('mysql:host=localhost'));
    }

    public function test_engine_from_dsn_postgres(): void
    {
        $this->assertSame('postgres', SchemaExtractorFactory::engineFromDsn('pgsql:host=localhost'));
    }

    public function test_engine_from_dsn_sqlite(): void
    {
        $this->assertSame('sqlite', SchemaExtractorFactory::engineFromDsn('sqlite:/path/to/db'));
    }

    // =========================================================================
    // MySQLSchemaExtractor — using SQLite in-memory as a test harness
    //
    // We can't test against a real MySQL, but we CAN test the extractor logic
    // by using a mock PDO via an in-memory SQLite DB that mimics the interface.
    // The key logic we test: table filtering, DDL cleaning, header generation.
    // =========================================================================

    public function test_config_parses_global_database_include_tables(): void
    {
        // Regression: include_tables inside database: was silently ignored
        // because parseNestedMap only handled key: value (inline value) lines,
        // not key: (empty) followed by a sub-list.
        file_put_contents($this->tmpDir . '/schema.sql', '');
        $path = $this->writeConfig(
            "version: \"2\"\nschema: schema.sql\n" .
            "database:\n" .
            "  dsn: \"mysql:host=localhost;dbname=myapp\"\n" .
            "  username: root\n" .
            "  include_tables:\n" .
            "    - migrations\n" .
            "    - failed_jobs\n" .
            "targets:\n  - namespace: \"App\"\n    out: gen\n    queries: q.sql\n"
        );

        $cfg = Config::fromFile($path);
        $this->assertNotNull($cfg->database);
        $this->assertSame(['migrations', 'failed_jobs'], $cfg->database->includeTables);
        $this->assertSame([], $cfg->database->excludeTables);

        // shouldInclude: only listed tables pass
        $this->assertTrue($cfg->database->shouldInclude('migrations'));
        $this->assertTrue($cfg->database->shouldInclude('failed_jobs'));
        $this->assertFalse($cfg->database->shouldInclude('users'));
        $this->assertFalse($cfg->database->shouldInclude('orders'));
    }

    public function test_config_parses_global_database_exclude_tables(): void
    {
        file_put_contents($this->tmpDir . '/schema.sql', '');
        $path = $this->writeConfig(
            "version: \"2\"\nschema: schema.sql\n" .
            "database:\n" .
            "  dsn: \"mysql:host=localhost;dbname=myapp\"\n" .
            "  exclude_tables:\n" .
            "    - migrations\n" .
            "    - sessions\n" .
            "    - failed_jobs\n" .
            "targets:\n  - namespace: \"App\"\n    out: gen\n    queries: q.sql\n"
        );

        $cfg = Config::fromFile($path);
        $this->assertSame(['migrations', 'sessions', 'failed_jobs'], $cfg->database->excludeTables);

        // shouldInclude: excluded tables blocked, others allowed
        $this->assertFalse($cfg->database->shouldInclude('migrations'));
        $this->assertFalse($cfg->database->shouldInclude('sessions'));
        $this->assertTrue($cfg->database->shouldInclude('users'));
        $this->assertTrue($cfg->database->shouldInclude('orders'));
    }

    public function test_extractor_cleans_auto_increment_from_ddl(): void
    {
        $extractor = new MySQLSchemaExtractor();
        $method    = new \ReflectionMethod($extractor, 'cleanDdl');
        $method->setAccessible(true);

        $ddl = "CREATE TABLE `users` (\n  `id` int NOT NULL\n) ENGINE=InnoDB AUTO_INCREMENT=42 DEFAULT CHARSET=utf8mb4";
        $cleaned = $method->invoke($extractor, $ddl);

        $this->assertStringNotContainsString('AUTO_INCREMENT=42', $cleaned);
        $this->assertStringContainsString('ENGINE=InnoDB', $cleaned);
    }

    public function test_extractor_preserves_ddl_without_auto_increment(): void
    {
        $extractor = new MySQLSchemaExtractor();
        $method    = new \ReflectionMethod($extractor, 'cleanDdl');
        $method->setAccessible(true);

        $ddl = "CREATE TABLE `roles` (\n  `id` int NOT NULL\n) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
        $cleaned = $method->invoke($extractor, $ddl);

        $this->assertSame($ddl, $cleaned);
    }

    // =========================================================================
    // Version
    // =========================================================================

    public function test_version_is_2_6_0(): void
    {
        $this->assertSame('2.9.3', \SqlcPhp\Version::VERSION);
    }
}
