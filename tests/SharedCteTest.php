<?php

declare(strict_types=1);

namespace SqlcPhp\Tests;

use PHPUnit\Framework\TestCase;
use SqlcPhp\Analyzer\QueryAnalyzer;
use SqlcPhp\Catalog\SchemaCatalog;
use SqlcPhp\Config\CteRegistry;
use SqlcPhp\Generator\EnumGenerator;
use SqlcPhp\Parser\CteDefinition;
use SqlcPhp\Parser\CteParser;
use SqlcPhp\Parser\QueryParser;
use SqlcPhp\Parser\SchemaParser;
use SqlcPhp\Resolver\ColumnResolver;
use SqlcPhp\Resolver\ExpressionTypeResolver;
use SqlcPhp\Resolver\ParamResolver;
use SqlcPhp\Rewriter\SqlRewriter;
use SqlcPhp\TypeMapper\MySQLTypeMapper;

/**
 * Tests for the shared CTE feature (v2.13.0).
 *
 * Covers:
 *   - CteParser: parsing @cte blocks from SQL strings and files
 *   - CteRegistry: building, duplicate detection, injection
 *   - QueryParser: @use annotation parsing into usedCtes
 *   - Integration: @use + CteRegistry produces correct WITH clause
 */
class SharedCteTest extends TestCase
{
    private QueryParser   $parser;
    private QueryAnalyzer $analyzer;
    private string        $tmpDir;

    protected function setUp(): void
    {
        $schema = <<<SQL
            CREATE TABLE users   (id INT AUTO_INCREMENT PRIMARY KEY, email VARCHAR(100) NOT NULL, active TINYINT NOT NULL DEFAULT 1, role VARCHAR(20) NOT NULL DEFAULT 'client');
            CREATE TABLE orders  (id INT AUTO_INCREMENT PRIMARY KEY, user_id INT NOT NULL, total DECIMAL(10,2) NOT NULL, status VARCHAR(20) NOT NULL);
            CREATE TABLE payments(id INT AUTO_INCREMENT PRIMARY KEY, order_id INT NOT NULL, amount DECIMAL(10,2) NOT NULL);
        SQL;

        $catalog        = new SchemaCatalog((new SchemaParser())->parse($schema));
        $mapper         = new MySQLTypeMapper([], new EnumGenerator('App'));
        $this->parser   = new QueryParser();
        $pr             = new ParamResolver($catalog, $mapper);
        $er             = new ExpressionTypeResolver($catalog, $mapper);
        $cr             = new ColumnResolver($catalog, $mapper, $pr, $er);
        $this->analyzer = new QueryAnalyzer($pr, $cr, $this->parser, new SqlRewriter(), $catalog);
        $this->tmpDir   = sys_get_temp_dir() . '/sqlc-php-cte-test-' . uniqid();
        mkdir($this->tmpDir, 0755, true);
    }

    protected function tearDown(): void
    {
        // Clean up temp files
        foreach (glob($this->tmpDir . '/*') ?: [] as $f) unlink($f);
        rmdir($this->tmpDir);
    }

    private function tmpFile(string $name, string $content): string
    {
        $path = $this->tmpDir . '/' . $name;
        file_put_contents($path, $content);
        return $path;
    }

    // =========================================================================
    // CteParser — string parsing
    // =========================================================================

    public function test_cte_parser_single_block(): void
    {
        $parser = new CteParser();
        $defs   = $parser->parse(<<<SQL
            -- @cte active_users
            SELECT id, email FROM users WHERE active = 1;
        SQL);

        $this->assertCount(1, $defs);
        $this->assertSame('active_users', $defs[0]->name);
        $this->assertStringContainsString('SELECT id, email FROM users', $defs[0]->sql);
    }

    public function test_cte_parser_multiple_blocks(): void
    {
        $parser = new CteParser();
        $defs   = $parser->parse(<<<SQL
            -- @cte active_users
            SELECT id FROM users WHERE active = 1;

            -- @cte recent_orders
            SELECT id, user_id FROM orders WHERE status = 'pending';
        SQL);

        $this->assertCount(2, $defs);
        $this->assertSame('active_users',  $defs[0]->name);
        $this->assertSame('recent_orders', $defs[1]->name);
    }

    public function test_cte_parser_strips_trailing_semicolon(): void
    {
        $parser = new CteParser();
        $defs   = $parser->parse("-- @cte active_users\nSELECT id FROM users WHERE active = 1;");

        $this->assertStringEndsNotWith(';', rtrim($defs[0]->sql));
    }

    public function test_cte_parser_preserves_multiline_body(): void
    {
        $parser = new CteParser();
        $defs   = $parser->parse(<<<SQL
            -- @cte active_users
            SELECT
                id,
                email,
                role
            FROM users
            WHERE active = 1
              AND role = 'client';
        SQL);

        $this->assertStringContainsString('SELECT', $defs[0]->sql);
        $this->assertStringContainsString('email', $defs[0]->sql);
        $this->assertStringContainsString("role = 'client'", $defs[0]->sql);
    }

    public function test_cte_parser_ignores_content_before_first_annotation(): void
    {
        $parser = new CteParser();
        $defs   = $parser->parse(<<<SQL
            -- This file contains shared CTEs for the Orders module
            -- Maintainer: dev@example.com

            -- @cte active_users
            SELECT id FROM users WHERE active = 1;
        SQL);

        $this->assertCount(1, $defs);
        $this->assertSame('active_users', $defs[0]->name);
    }

    public function test_cte_parser_throws_on_empty_body(): void
    {
        $parser = new CteParser();
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/empty body/');
        $parser->parse("-- @cte active_users\n\n-- @cte other\nSELECT 1;");
    }

    public function test_cte_parser_records_source_file(): void
    {
        $parser = new CteParser();
        $defs   = $parser->parse("-- @cte active_users\nSELECT 1;", '/path/to/ctes.sql');

        $this->assertSame('/path/to/ctes.sql', $defs[0]->sourceFile);
    }

    public function test_cte_parser_empty_file_returns_empty_array(): void
    {
        $parser = new CteParser();
        $this->assertSame([], $parser->parse(''));
        $this->assertSame([], $parser->parse("-- just a comment\n\n"));
    }

    public function test_cte_parser_reads_file_from_disk(): void
    {
        $path   = $this->tmpFile('ctes.sql', "-- @cte active_users\nSELECT id FROM users WHERE active = 1;");
        $parser = new CteParser();
        $defs   = $parser->parseFile($path);

        $this->assertCount(1, $defs);
        $this->assertSame('active_users', $defs[0]->name);
        $this->assertSame($path, $defs[0]->sourceFile);
    }

    public function test_cte_parser_throws_on_missing_file(): void
    {
        $parser = new CteParser();
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/not found/');
        $parser->parseFile('/nonexistent/path/ctes.sql');
    }

    // =========================================================================
    // CteRegistry — building and lookup
    // =========================================================================

    public function test_registry_build_from_single_file(): void
    {
        $path = $this->tmpFile('ctes.sql', "-- @cte active_users\nSELECT id FROM users WHERE active = 1;");

        $registry = CteRegistry::build([$path], [], $this->tmpDir);

        $this->assertFalse($registry->isEmpty());
        $this->assertContains('active_users', $registry->names());
    }

    public function test_registry_build_from_multiple_files(): void
    {
        $f1 = $this->tmpFile('users_ctes.sql',  "-- @cte active_users\nSELECT id FROM users WHERE active = 1;");
        $f2 = $this->tmpFile('orders_ctes.sql', "-- @cte recent_orders\nSELECT id FROM orders WHERE status = 'pending';");

        $registry = CteRegistry::build([$f1, $f2], [], $this->tmpDir);

        $this->assertContains('active_users',  $registry->names());
        $this->assertContains('recent_orders', $registry->names());
    }

    public function test_registry_global_and_target_paths_are_merged(): void
    {
        $global = $this->tmpFile('global.sql', "-- @cte active_users\nSELECT id FROM users WHERE active = 1;");
        $local  = $this->tmpFile('local.sql',  "-- @cte recent_orders\nSELECT id FROM orders;");

        $registry = CteRegistry::build([$global], [$local], $this->tmpDir);

        $this->assertContains('active_users',  $registry->names());
        $this->assertContains('recent_orders', $registry->names());
    }

    public function test_registry_empty_when_no_paths(): void
    {
        $registry = CteRegistry::build([], [], $this->tmpDir);
        $this->assertTrue($registry->isEmpty());
    }

    public function test_registry_throws_on_duplicate_cte_name(): void
    {
        $f1 = $this->tmpFile('a.sql', "-- @cte active_users\nSELECT id FROM users WHERE active = 1;");
        $f2 = $this->tmpFile('b.sql', "-- @cte active_users\nSELECT id FROM users WHERE role = 'admin';");

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches("/Duplicate CTE name 'active_users'/");
        CteRegistry::build([$f1, $f2], [], $this->tmpDir);
    }

    public function test_registry_get_returns_definition(): void
    {
        $path     = $this->tmpFile('ctes.sql', "-- @cte active_users\nSELECT id FROM users WHERE active = 1;");
        $registry = CteRegistry::build([$path], [], $this->tmpDir);

        $def = $registry->get('active_users');
        $this->assertInstanceOf(CteDefinition::class, $def);
        $this->assertSame('active_users', $def->name);
    }

    public function test_registry_get_returns_null_for_unknown(): void
    {
        $registry = CteRegistry::empty();
        $this->assertNull($registry->get('nonexistent'));
    }

    // =========================================================================
    // CteRegistry — injection
    // =========================================================================

    public function test_registry_inject_single_cte(): void
    {
        $path     = $this->tmpFile('ctes.sql', "-- @cte active_users\nSELECT id FROM users WHERE active = 1;");
        $registry = CteRegistry::build([$path], [], $this->tmpDir);

        $result = $registry->inject(['active_users'], 'SELECT orders.* FROM orders INNER JOIN active_users ON active_users.id = orders.user_id;');

        $this->assertStringContainsString('WITH', $result);
        $this->assertStringContainsString('active_users AS (', $result);
        $this->assertStringContainsString('SELECT id FROM users', $result);
        $this->assertStringContainsString('SELECT orders.*', $result);
    }

    public function test_registry_inject_multiple_ctes(): void
    {
        $path = $this->tmpFile('ctes.sql', implode("\n", [
            "-- @cte active_users",
            "SELECT id FROM users WHERE active = 1;",
            "-- @cte recent_orders",
            "SELECT id FROM orders WHERE status = 'pending';",
        ]));
        $registry = CteRegistry::build([$path], [], $this->tmpDir);

        $result = $registry->inject(
            ['active_users', 'recent_orders'],
            'SELECT payments.* FROM payments INNER JOIN recent_orders ON 1=1;'
        );

        $this->assertStringContainsString('active_users AS (', $result);
        $this->assertStringContainsString('recent_orders AS (', $result);
        // Multiple CTEs joined by comma
        $this->assertStringContainsString('),', $result);
    }

    public function test_registry_inject_preserves_order(): void
    {
        $path = $this->tmpFile('ctes.sql', implode("\n", [
            "-- @cte cte_a\nSELECT 1 AS a;",
            "-- @cte cte_b\nSELECT 2 AS b;",
        ]));
        $registry = CteRegistry::build([$path], [], $this->tmpDir);

        $result = $registry->inject(['cte_a', 'cte_b'], 'SELECT 1;');

        $posA = strpos($result, 'cte_a AS (');
        $posB = strpos($result, 'cte_b AS (');
        $this->assertLessThan($posB, $posA, 'cte_a must appear before cte_b');
    }

    public function test_registry_inject_throws_on_unknown_cte(): void
    {
        $registry = CteRegistry::empty();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches("/no CTE named 'nonexistent'/");
        $registry->inject(['nonexistent'], 'SELECT 1;');
    }

    public function test_registry_inject_throws_when_query_has_inline_with(): void
    {
        $path     = $this->tmpFile('ctes.sql', "-- @cte active_users\nSELECT id FROM users;");
        $registry = CteRegistry::build([$path], [], $this->tmpDir);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/inline WITH/');
        $registry->inject(['active_users'], 'WITH foo AS (SELECT 1) SELECT * FROM foo;');
    }

    public function test_registry_inject_empty_names_returns_original(): void
    {
        $registry = CteRegistry::empty();
        $sql      = 'SELECT 1;';
        $this->assertSame($sql, $registry->inject([], $sql));
    }

    // =========================================================================
    // QueryParser — @use annotation
    // =========================================================================

    public function test_parser_captures_single_use(): void
    {
        $queries = $this->parser->parse(<<<SQL
            -- @name ListActiveOrders
            -- @class Orders
            -- @returns :many
            -- @use active_users
            SELECT orders.* FROM orders INNER JOIN active_users ON active_users.id = orders.user_id;
        SQL);

        $this->assertSame(['active_users'], $queries[0]->usedCtes);
    }

    public function test_parser_captures_multiple_use_on_one_line(): void
    {
        $queries = $this->parser->parse(<<<SQL
            -- @name ListPayments
            -- @class Payments
            -- @returns :many
            -- @use active_users, recent_orders
            SELECT payments.* FROM payments;
        SQL);

        $this->assertSame(['active_users', 'recent_orders'], $queries[0]->usedCtes);
    }

    public function test_parser_captures_multiple_use_on_separate_lines(): void
    {
        $queries = $this->parser->parse(<<<SQL
            -- @name ListPayments
            -- @class Payments
            -- @returns :many
            -- @use active_users
            -- @use recent_orders
            SELECT payments.* FROM payments;
        SQL);

        $this->assertSame(['active_users', 'recent_orders'], $queries[0]->usedCtes);
    }

    public function test_parser_deduplicates_use(): void
    {
        $queries = $this->parser->parse(<<<SQL
            -- @name ListPayments
            -- @class Payments
            -- @returns :many
            -- @use active_users, active_users
            SELECT payments.* FROM payments;
        SQL);

        $this->assertSame(['active_users'], $queries[0]->usedCtes);
    }

    public function test_parser_no_use_gives_empty_array(): void
    {
        $queries = $this->parser->parse(<<<SQL
            -- @name ListOrders
            -- @class Orders
            -- @returns :many
            SELECT orders.* FROM orders;
        SQL);

        $this->assertSame([], $queries[0]->usedCtes);
    }

    // =========================================================================
    // Integration — inject + analyze
    // =========================================================================

    public function test_injected_cte_is_transparent_to_analyzer(): void
    {
        $path     = $this->tmpFile('ctes.sql', "-- @cte active_users\nSELECT id, email FROM users WHERE active = 1;");
        $registry = CteRegistry::build([$path], [], $this->tmpDir);

        $rawQueries = $this->parser->parse(<<<SQL
            -- @name ListActiveUserOrders
            -- @class Orders
            -- @returns :many
            -- @use active_users
            SELECT orders.id, orders.total, orders.status
            FROM orders
            INNER JOIN active_users ON active_users.id = orders.user_id;
        SQL);

        // Inject CTE into SQL
        $rawQueries[0] = new \SqlcPhp\Parser\QueryDefinition(
            name:      $rawQueries[0]->name,
            group:     $rawQueries[0]->group,
            returns:   $rawQueries[0]->returns,
            sql:       $registry->inject($rawQueries[0]->usedCtes, $rawQueries[0]->sql),
            fromTable: $rawQueries[0]->fromTable,
            usedCtes:  $rawQueries[0]->usedCtes,
        );

        $queries = $this->analyzer->analyze($rawQueries);

        $this->assertSame('listActiveUserOrders', $queries[0]->name);
        $columnAliases = array_column($queries[0]->resultColumns, 'alias');
        $this->assertContains('id',     $columnAliases);
        $this->assertContains('total',  $columnAliases);
        $this->assertContains('status', $columnAliases);
    }

    public function test_injected_sql_starts_with_with_clause(): void
    {
        $path     = $this->tmpFile('ctes.sql', "-- @cte active_users\nSELECT id FROM users WHERE active = 1;");
        $registry = CteRegistry::build([$path], [], $this->tmpDir);

        $rawSql   = "SELECT orders.* FROM orders INNER JOIN active_users ON active_users.id = orders.user_id;";
        $injected = $registry->inject(['active_users'], $rawSql);

        $this->assertStringStartsWith('WITH', ltrim($injected));
    }

    public function test_multiple_queries_can_reuse_same_cte(): void
    {
        $path     = $this->tmpFile('ctes.sql', "-- @cte active_users\nSELECT id FROM users WHERE active = 1;");
        $registry = CteRegistry::build([$path], [], $this->tmpDir);

        $sql1 = $registry->inject(['active_users'], 'SELECT orders.id   FROM orders   INNER JOIN active_users ON active_users.id = orders.user_id;');
        $sql2 = $registry->inject(['active_users'], 'SELECT payments.id FROM payments INNER JOIN active_users ON active_users.id = payments.id;');

        $this->assertStringContainsString('active_users AS (', $sql1);
        $this->assertStringContainsString('active_users AS (', $sql2);
        // The CTE definition is the same in both
        $this->assertSame(
            substr($sql1, 0, strpos($sql1, 'SELECT orders')),
            substr($sql2, 0, strpos($sql2, 'SELECT payments'))
        );
    }
}
