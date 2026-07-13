<?php

declare(strict_types=1);

namespace SqlcPhp\Tests;

use PHPUnit\Framework\TestCase;
use SqlcPhp\Config\DatabaseConfig;
use SqlcPhp\Parser\SchemaParser;
use SqlcPhp\SchemaExtractor\MySQLSchemaExtractor;

/**
 * Tests for view support in --generate-schema and SchemaParser (v2.14.2).
 *
 * Covers:
 *   - DatabaseConfig: includeViews, excludeViews, includeViewsOnly, shouldIncludeView()
 *   - SchemaParser:   CREATE OR REPLACE VIEW parsing → virtual TableDefinition
 *   - MySQLSchemaExtractor: cleanViewDdl() normalization
 */
class ViewSupportTest extends TestCase
{
    // =========================================================================
    // DatabaseConfig — view filter config
    // =========================================================================

    public function test_should_include_view_returns_true_by_default(): void
    {
        // Views are included by default — no config needed
        $cfg = DatabaseConfig::fromArray(['dsn' => 'mysql:host=localhost;dbname=test']);
        $this->assertTrue($cfg->shouldIncludeView('v_active_users'));
        $this->assertTrue($cfg->shouldIncludeView('v_report'));
    }

    public function test_include_views_acts_as_whitelist(): void
    {
        $cfg = DatabaseConfig::fromArray([
            'dsn'           => 'mysql:host=localhost;dbname=test',
            'include_views' => ['v_active_users', 'v_report'],
        ]);
        $this->assertTrue($cfg->shouldIncludeView('v_active_users'));
        $this->assertTrue($cfg->shouldIncludeView('v_report'));
        $this->assertFalse($cfg->shouldIncludeView('v_other'));
    }

    public function test_exclude_views_acts_as_blacklist(): void
    {
        $cfg = DatabaseConfig::fromArray([
            'dsn'           => 'mysql:host=localhost;dbname=test',
            'exclude_views' => ['v_legacy', 'v_deprecated'],
        ]);
        $this->assertTrue($cfg->shouldIncludeView('v_active_users'));
        $this->assertFalse($cfg->shouldIncludeView('v_legacy'));
        $this->assertFalse($cfg->shouldIncludeView('v_deprecated'));
    }

    public function test_include_views_whitelist_takes_precedence_over_exclude_views(): void
    {
        // When include_views is set, exclude_views is ignored (whitelist wins)
        $cfg = DatabaseConfig::fromArray([
            'dsn'           => 'mysql:host=localhost;dbname=test',
            'include_views' => ['v_active_users'],
            'exclude_views' => ['v_active_users'],
        ]);
        $this->assertTrue($cfg->shouldIncludeView('v_active_users'));
        $this->assertFalse($cfg->shouldIncludeView('v_other'));
    }

    public function test_empty_include_and_exclude_views_passes_all(): void
    {
        // No view config — every view passes
        $cfg = DatabaseConfig::fromArray(['dsn' => 'mysql:host=localhost;dbname=test']);
        $this->assertTrue($cfg->shouldIncludeView('v_anything'));
        $this->assertTrue($cfg->shouldIncludeView('v_whatever'));
    }

    public function test_view_config_mirrors_table_config_semantics(): void
    {
        // include_views behaves like include_tables — whitelist
        $cfgViews  = DatabaseConfig::fromArray(['dsn' => 'x', 'include_views'  => ['v_a']]);
        $cfgTables = DatabaseConfig::fromArray(['dsn' => 'x', 'include_tables' => ['a']]);

        $this->assertTrue($cfgViews->shouldIncludeView('v_a'));
        $this->assertFalse($cfgViews->shouldIncludeView('v_b'));
        $this->assertTrue($cfgTables->shouldInclude('a'));
        $this->assertFalse($cfgTables->shouldInclude('b'));

        // exclude_views behaves like exclude_tables — blacklist
        $cfgExViews  = DatabaseConfig::fromArray(['dsn' => 'x', 'exclude_views'  => ['v_x']]);
        $cfgExTables = DatabaseConfig::fromArray(['dsn' => 'x', 'exclude_tables' => ['x']]);

        $this->assertFalse($cfgExViews->shouldIncludeView('v_x'));
        $this->assertTrue($cfgExViews->shouldIncludeView('v_other'));
        $this->assertFalse($cfgExTables->shouldInclude('x'));
        $this->assertTrue($cfgExTables->shouldInclude('other'));
    }

    // =========================================================================
    // SchemaParser — CREATE OR REPLACE VIEW parsing
    // =========================================================================

    public function test_parser_parses_simple_view(): void
    {
        $parser = new SchemaParser();
        $tables = $parser->parse(<<<SQL
            CREATE OR REPLACE VIEW v_active_users AS
            SELECT id, email, name FROM users WHERE active = 1;
        SQL);

        $this->assertCount(1, $tables);
        $this->assertSame('v_active_users', $tables[0]->name);
        $this->assertTrue($tables[0]->virtual);
    }

    public function test_parser_view_columns_extracted_by_alias(): void
    {
        $parser = new SchemaParser();
        $tables = $parser->parse(<<<SQL
            CREATE OR REPLACE VIEW v_report AS
            SELECT
                users.id         AS user_id,
                users.email      AS user_email,
                COUNT(orders.id) AS order_count
            FROM users
            LEFT JOIN orders ON orders.user_id = users.id
            GROUP BY users.id;
        SQL);

        $this->assertCount(1, $tables);
        $colNames = array_map(fn($c) => $c->name, $tables[0]->columns);
        $this->assertContains('user_id',     $colNames);
        $this->assertContains('user_email',  $colNames);
        $this->assertContains('order_count', $colNames);
    }

    public function test_parser_view_columns_extracted_without_alias(): void
    {
        $parser = new SchemaParser();
        $tables = $parser->parse(<<<SQL
            CREATE OR REPLACE VIEW v_simple AS
            SELECT id, email, name FROM users;
        SQL);

        $colNames = array_map(fn($c) => $c->name, $tables[0]->columns);
        $this->assertContains('id',    $colNames);
        $this->assertContains('email', $colNames);
        $this->assertContains('name',  $colNames);
    }

    public function test_parser_view_columns_mixed_alias_and_bare(): void
    {
        $parser = new SchemaParser();
        $tables = $parser->parse(<<<SQL
            CREATE OR REPLACE VIEW v_mixed AS
            SELECT
                users.id,
                users.email AS user_email,
                users.name
            FROM users;
        SQL);

        $colNames = array_map(fn($c) => $c->name, $tables[0]->columns);
        $this->assertContains('id',         $colNames);
        $this->assertContains('user_email', $colNames);
        $this->assertContains('name',       $colNames);
    }

    public function test_parser_view_columns_are_nullable(): void
    {
        // Views may propagate NULLs from JOINs — default nullable=true
        $parser = new SchemaParser();
        $tables = $parser->parse(<<<SQL
            CREATE OR REPLACE VIEW v_users AS
            SELECT id, email FROM users;
        SQL);

        foreach ($tables[0]->columns as $col) {
            $this->assertTrue($col->nullable);
        }
    }

    public function test_parser_view_marked_as_virtual(): void
    {
        $parser = new SchemaParser();
        $tables = $parser->parse(<<<SQL
            CREATE OR REPLACE VIEW v_test AS SELECT id FROM users;
        SQL);

        $this->assertTrue($tables[0]->virtual);
    }

    public function test_parser_handles_create_view_without_or_replace(): void
    {
        $parser = new SchemaParser();
        $tables = $parser->parse(<<<SQL
            CREATE VIEW v_simple AS SELECT id, email FROM users;
        SQL);

        $this->assertCount(1, $tables);
        $this->assertSame('v_simple', $tables[0]->name);
    }

    public function test_parser_handles_tables_and_views_together(): void
    {
        $parser = new SchemaParser();
        $tables = $parser->parse(<<<SQL
            CREATE TABLE users (
                id    INT AUTO_INCREMENT PRIMARY KEY,
                email VARCHAR(100) NOT NULL
            );

            CREATE OR REPLACE VIEW v_active_users AS
            SELECT id, email FROM users WHERE active = 1;
        SQL);

        $this->assertCount(2, $tables);

        $tableNames = array_map(fn($t) => $t->name, $tables);
        $this->assertContains('users',          $tableNames);
        $this->assertContains('v_active_users', $tableNames);

        // Table is not virtual, view is
        $byName = array_combine($tableNames, $tables);
        $this->assertFalse($byName['users']->virtual);
        $this->assertTrue($byName['v_active_users']->virtual);
    }

    public function test_parser_view_with_subquery_in_select(): void
    {
        // Subqueries in SELECT have nested parens — split must handle them
        $parser = new SchemaParser();
        $tables = $parser->parse(<<<SQL
            CREATE OR REPLACE VIEW v_summary AS
            SELECT
                users.id,
                (SELECT COUNT(*) FROM orders WHERE orders.user_id = users.id) AS order_count,
                users.email AS user_email
            FROM users;
        SQL);

        $this->assertCount(1, $tables);
        $colNames = array_map(fn($c) => $c->name, $tables[0]->columns);
        $this->assertContains('id',          $colNames);
        $this->assertContains('order_count', $colNames);
        $this->assertContains('user_email',  $colNames);
    }

    public function test_parser_view_star_select_skipped(): void
    {
        // SELECT * cannot be typed statically — those items are skipped
        $parser = new SchemaParser();
        $tables = $parser->parse(<<<SQL
            CREATE OR REPLACE VIEW v_all AS
            SELECT *, users.email AS user_email FROM users;
        SQL);

        $this->assertCount(1, $tables);
        $colNames = array_map(fn($c) => $c->name, $tables[0]->columns);
        // * is skipped, user_email is captured
        $this->assertContains('user_email', $colNames);
        $this->assertNotContains('*', $colNames);
    }

    // =========================================================================
    // MySQLSchemaExtractor — cleanViewDdl normalization
    // =========================================================================

    public function test_clean_view_ddl_removes_definer(): void
    {
        $extractor = new MySQLSchemaExtractor();
        $method    = new \ReflectionMethod($extractor, 'cleanViewDdl');
        $method->setAccessible(true);

        $ddl     = "CREATE ALGORITHM=UNDEFINED DEFINER=`root`@`%` SQL SECURITY DEFINER VIEW `v_test` AS SELECT 1";
        $cleaned = $method->invoke($extractor, $ddl);

        $this->assertStringNotContainsString('DEFINER',       $cleaned);
        $this->assertStringNotContainsString('root',          $cleaned);
        $this->assertStringNotContainsString('SQL SECURITY',  $cleaned);
        $this->assertStringNotContainsString('ALGORITHM',     $cleaned);
    }

    public function test_clean_view_ddl_normalizes_to_create_or_replace(): void
    {
        $extractor = new MySQLSchemaExtractor();
        $method    = new \ReflectionMethod($extractor, 'cleanViewDdl');
        $method->setAccessible(true);

        $ddl     = "CREATE ALGORITHM=UNDEFINED DEFINER=`root`@`%` SQL SECURITY DEFINER VIEW `v_test` AS SELECT id FROM users";
        $cleaned = $method->invoke($extractor, $ddl);

        $this->assertStringStartsWith('CREATE OR REPLACE VIEW', $cleaned);
    }

    public function test_clean_view_ddl_preserves_select_body(): void
    {
        $extractor = new MySQLSchemaExtractor();
        $method    = new \ReflectionMethod($extractor, 'cleanViewDdl');
        $method->setAccessible(true);

        $ddl     = "CREATE ALGORITHM=UNDEFINED DEFINER=`root`@`localhost` SQL SECURITY DEFINER VIEW `v_active_users` AS SELECT `id`,`email` FROM `users` WHERE `active` = 1";
        $cleaned = $method->invoke($extractor, $ddl);

        $this->assertStringContainsString('v_active_users', $cleaned);
        $this->assertStringContainsString('SELECT',         $cleaned);
        $this->assertStringContainsString('WHERE',          $cleaned);
        $this->assertStringContainsString('active',         $cleaned);
    }

    public function test_clean_view_ddl_handles_host_with_percent(): void
    {
        $extractor = new MySQLSchemaExtractor();
        $method    = new \ReflectionMethod($extractor, 'cleanViewDdl');
        $method->setAccessible(true);

        $ddl     = "CREATE DEFINER=`app_user`@`%` VIEW `v_test` AS SELECT 1 AS val";
        $cleaned = $method->invoke($extractor, $ddl);

        $this->assertStringNotContainsString('app_user', $cleaned);
        $this->assertStringContainsString('v_test',      $cleaned);
        $this->assertStringContainsString('SELECT 1',    $cleaned);
    }

    public function test_clean_view_ddl_handles_already_clean_ddl(): void
    {
        $extractor = new MySQLSchemaExtractor();
        $method    = new \ReflectionMethod($extractor, 'cleanViewDdl');
        $method->setAccessible(true);

        $ddl     = "CREATE OR REPLACE VIEW `v_test` AS SELECT id, email FROM users WHERE active = 1";
        $cleaned = $method->invoke($extractor, $ddl);

        $this->assertStringStartsWith('CREATE OR REPLACE VIEW', $cleaned);
        $this->assertStringContainsString('v_test',  $cleaned);
        $this->assertStringContainsString('SELECT',  $cleaned);
    }

    // =========================================================================
    // Integration — view in schema used as query source
    // =========================================================================

    public function test_view_can_be_used_as_query_from_table(): void
    {
        $parser = new SchemaParser();
        $schema = <<<SQL
            CREATE TABLE users (
                id     INT AUTO_INCREMENT PRIMARY KEY,
                email  VARCHAR(100) NOT NULL,
                active TINYINT NOT NULL DEFAULT 1
            );

            CREATE OR REPLACE VIEW v_active_users AS
            SELECT id, email FROM users WHERE active = 1;
        SQL;

        $tables = $parser->parse($schema);
        $byName = array_combine(
            array_map(fn($t) => $t->name, $tables),
            $tables
        );

        $this->assertArrayHasKey('v_active_users', $byName);
        $view = $byName['v_active_users'];

        $this->assertTrue($view->virtual);
        $colNames = array_map(fn($c) => $c->name, $view->columns);
        $this->assertContains('id',    $colNames);
        $this->assertContains('email', $colNames);
    }
}
