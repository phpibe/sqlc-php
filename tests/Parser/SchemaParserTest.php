<?php

declare(strict_types=1);

namespace SqlcPhp\Tests\Parser;

use PHPUnit\Framework\TestCase;
use SqlcPhp\Parser\SchemaParser;
use SqlcPhp\Parser\TableDefinition;

class SchemaParserTest extends TestCase
{
    private SchemaParser $parser;

    protected function setUp(): void
    {
        $this->parser = new SchemaParser();
    }

    // -------------------------------------------------------------------------
    // Basic parsing
    // -------------------------------------------------------------------------

    public function test_parses_single_table(): void
    {
        $sql = <<<SQL
            CREATE TABLE users (
                id    INT NOT NULL,
                email VARCHAR(100) NOT NULL
            );
        SQL;

        $tables = $this->parser->parse($sql);

        $this->assertCount(1, $tables);
        $this->assertSame('users', $tables[0]->name);
    }

    public function test_parses_all_columns(): void
    {
        $tables = $this->parser->parse($this->usersSchema());
        $names  = array_map(fn($c) => $c->name, $tables[0]->columns);

        $this->assertContains('id',       $names);
        $this->assertContains('email',    $names);
        $this->assertContains('active',   $names);
        $this->assertContains('created_at', $names);
    }

    public function test_parses_all_22_columns_from_users(): void
    {
        $tables = $this->parser->parse($this->fullUsersSchema());
        $this->assertCount(22, $tables[0]->columns);
    }

    public function test_parses_multiple_tables(): void
    {
        $sql = <<<SQL
            CREATE TABLE users (id INT NOT NULL);
            CREATE TABLE roles (id SMALLINT NOT NULL, name VARCHAR(100) NOT NULL);
        SQL;

        $tables = $this->parser->parse($sql);

        $this->assertCount(2, $tables);
        $this->assertSame('users', $tables[0]->name);
        $this->assertSame('roles', $tables[1]->name);
    }

    // -------------------------------------------------------------------------
    // Nullability
    // -------------------------------------------------------------------------

    public function test_not_null_columns_are_not_nullable(): void
    {
        $sql  = "CREATE TABLE t (email VARCHAR(100) NOT NULL);";
        $col  = $this->parser->parse($sql)[0]->columns[0];

        $this->assertFalse($col->nullable);
    }

    public function test_columns_without_not_null_are_nullable(): void
    {
        $sql = "CREATE TABLE t (nickname VARCHAR(50) null);";
        $col = $this->parser->parse($sql)[0]->columns[0];

        $this->assertTrue($col->nullable);
    }

    // -------------------------------------------------------------------------
    // SQL types
    // -------------------------------------------------------------------------

    public function test_int_type_is_parsed(): void
    {
        $sql = "CREATE TABLE t (id INT NOT NULL);";
        $col = $this->parser->parse($sql)[0]->columns[0];

        $this->assertSame('INT', $col->sqlType);
    }

    public function test_tinyint_type_is_parsed(): void
    {
        $sql = "CREATE TABLE t (active TINYINT NOT NULL);";
        $col = $this->parser->parse($sql)[0]->columns[0];

        $this->assertSame('TINYINT', $col->sqlType);
    }

    public function test_varchar_type_strips_display_width(): void
    {
        $sql = "CREATE TABLE t (email VARCHAR(100) NOT NULL);";
        $col = $this->parser->parse($sql)[0]->columns[0];

        $this->assertSame('VARCHAR', $col->sqlType);
    }

    public function test_timestamp_type_is_parsed(): void
    {
        $sql = "CREATE TABLE t (created_at TIMESTAMP null);";
        $col = $this->parser->parse($sql)[0]->columns[0];

        $this->assertSame('TIMESTAMP', $col->sqlType);
    }

    // -------------------------------------------------------------------------
    // AUTO_INCREMENT & DEFAULT
    // -------------------------------------------------------------------------

    public function test_auto_increment_is_detected(): void
    {
        $sql = "CREATE TABLE t (id INT AUTO_INCREMENT PRIMARY KEY);";
        $col = $this->parser->parse($sql)[0]->columns[0];

        $this->assertTrue($col->autoIncrement);
    }

    public function test_default_value_is_captured(): void
    {
        $sql = "CREATE TABLE t (active TINYINT DEFAULT 1 null);";
        $col = $this->parser->parse($sql)[0]->columns[0];

        $this->assertSame('1', $col->default);
    }

    public function test_column_without_default_is_null(): void
    {
        $sql = "CREATE TABLE t (email VARCHAR(100) NOT NULL);";
        $col = $this->parser->parse($sql)[0]->columns[0];

        $this->assertNull($col->default);
    }

    // -------------------------------------------------------------------------
    // Robustness
    // -------------------------------------------------------------------------

    public function test_strips_single_line_comments(): void
    {
        $sql = <<<SQL
            -- This is a comment
            CREATE TABLE t (
                -- another comment
                id INT NOT NULL
            );
        SQL;

        $tables = $this->parser->parse($sql);
        $this->assertCount(1, $tables);
        $this->assertCount(1, $tables[0]->columns);
    }

    public function test_skips_primary_key_constraints(): void
    {
        $sql = <<<SQL
            CREATE TABLE t (
                id    INT NOT NULL,
                email VARCHAR(100) NOT NULL,
                PRIMARY KEY (id)
            );
        SQL;

        $cols = $this->parser->parse($sql)[0]->columns;
        $names = array_map(fn($c) => $c->name, $cols);

        $this->assertCount(2, $cols);
        $this->assertNotContains('PRIMARY', $names);
    }

    public function test_handles_default_with_quoted_string(): void
    {
        $sql = "CREATE TABLE t (avatar VARCHAR(100) DEFAULT 'avatar-default.svg' null);";
        $col = $this->parser->parse($sql)[0]->columns[0];

        $this->assertSame('avatar', $col->name);
        $this->assertTrue($col->nullable);
    }

    public function test_returns_empty_array_for_empty_input(): void
    {
        $this->assertSame([], $this->parser->parse(''));
        $this->assertSame([], $this->parser->parse('-- just a comment'));
    }

    // -------------------------------------------------------------------------
    // Fixtures
    // -------------------------------------------------------------------------

    private function usersSchema(): string
    {
        return <<<SQL
            CREATE TABLE users (
                id         INT AUTO_INCREMENT PRIMARY KEY,
                email      VARCHAR(100) NOT NULL,
                active     TINYINT DEFAULT 1 null,
                created_at TIMESTAMP null
            );
        SQL;
    }

    private function fullUsersSchema(): string
    {
        // Resolve to <project-root>/schema.sql regardless of cwd
        $path = dirname(__DIR__, 1) . '/schema.sql';
        $this->assertFileExists($path, 'schema.sql not found — run tests from the project root or any directory');
        return (string) file_get_contents($path);
    }
}
