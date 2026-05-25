<?php

declare(strict_types=1);

namespace SqlcPhp\Tests;

use PHPUnit\Framework\TestCase;
use SqlcPhp\Analyzer\QueryAnalyzer;
use SqlcPhp\Catalog\SchemaCatalog;
use SqlcPhp\Generator\EmbedGenerator;
use SqlcPhp\Generator\EnumGenerator;
use SqlcPhp\Generator\QueryGenerator;
use SqlcPhp\Generator\ResultDtoGenerator;
use SqlcPhp\Parser\EmbedDefinition;
use SqlcPhp\Parser\QueryParser;
use SqlcPhp\Parser\SchemaParser;
use SqlcPhp\Resolver\ColumnResolver;
use SqlcPhp\Resolver\ExpressionTypeResolver;
use SqlcPhp\Resolver\ParamResolver;
use SqlcPhp\Resolver\ResolvedColumn;
use SqlcPhp\Rewriter\SqlRewriter;
use SqlcPhp\TypeMapper\MySQLTypeMapper;

class BugFixTest extends TestCase
{
    // =========================================================================
    // Shared helpers
    // =========================================================================

    private function makeEnv(string $schema): array
    {
        $catalog  = new SchemaCatalog((new SchemaParser())->parse($schema));
        $mapper   = new MySQLTypeMapper([], new EnumGenerator('App'));
        $parser   = new QueryParser();
        $pr       = new ParamResolver($catalog, $mapper);
        $er       = new ExpressionTypeResolver($catalog, $mapper);
        $cr       = new ColumnResolver($catalog, $mapper, $pr, $er);
        $az       = new QueryAnalyzer($pr, $cr, $parser, new SqlRewriter());
        $dg       = new ResultDtoGenerator('App');
        $qg       = new QueryGenerator($catalog, $mapper, $dg, 'App');
        return [$catalog, $mapper, $parser, $az, $dg, $qg];
    }

    private function analyze(string $schema, string $sql): array
    {
        [,, $parser, $az] = $this->makeEnv($schema);
        return $az->analyze($parser->parse($sql));
    }

    // =========================================================================
    // Bug 1 — :many-paginated throws when SQL already has LIMIT
    // =========================================================================

    public function test_paginated_throws_when_sql_already_has_limit(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/already contains a LIMIT clause/');

        $this->analyze(
            'CREATE TABLE users (id INT PRIMARY KEY, email VARCHAR(100) NOT NULL);',
            "-- @name List\n-- @returns :many-paginated\nSELECT * FROM users LIMIT 10;"
        );
    }

    public function test_paginated_error_message_contains_query_name(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches("/Query 'listUsers'/");

        $this->analyze(
            'CREATE TABLE users (id INT PRIMARY KEY, email VARCHAR(100) NOT NULL);',
            "-- @name ListUsers\n-- @returns :many-paginated\nSELECT * FROM users LIMIT 10;"
        );
    }

    public function test_paginated_works_without_existing_limit(): void
    {
        $q = $this->analyze(
            'CREATE TABLE users (id INT PRIMARY KEY, email VARCHAR(100) NOT NULL);',
            "-- @name List\n-- @returns :many-paginated\nSELECT * FROM users ORDER BY id;"
        );

        $this->assertStringContainsString('LIMIT :limit', $q[0]->sql);
        $this->assertSame(1, substr_count($q[0]->sql, 'LIMIT'));
    }

    // =========================================================================
    // Bug 2 — :many-paginated throws when user has :limit or :offset param
    // =========================================================================

    public function test_paginated_throws_on_limit_param_collision(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches("/parameter named ':limit'/");

        $this->analyze(
            'CREATE TABLE users (id INT PRIMARY KEY, active TINYINT NOT NULL);',
            "-- @name List\n-- @returns :many-paginated\nSELECT * FROM users WHERE active = :limit;"
        );
    }

    public function test_paginated_throws_on_offset_param_collision(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches("/parameter named ':offset'/");

        $this->analyze(
            'CREATE TABLE users (id INT PRIMARY KEY, active TINYINT NOT NULL);',
            "-- @name List\n-- @returns :many-paginated\nSELECT * FROM users WHERE active = :offset;"
        );
    }

    // =========================================================================
    // Bug 3 — SchemaParser handles backtick-quoted table and column names
    // =========================================================================

    public function test_schema_parser_backtick_table_name(): void
    {
        $tables = (new SchemaParser())->parse(
            'CREATE TABLE `user_sessions` (`session_id` VARCHAR(128) PRIMARY KEY, `user_id` INT NOT NULL);'
        );

        $this->assertCount(1, $tables);
        $this->assertSame('user_sessions', $tables[0]->name);
    }

    public function test_schema_parser_backtick_columns_resolve_names(): void
    {
        $tables = (new SchemaParser())->parse(
            'CREATE TABLE `user_sessions` (`session_id` VARCHAR(128) PRIMARY KEY, `user_id` INT NOT NULL);'
        );

        $names = array_map(fn($c) => $c->name, $tables[0]->columns);
        $this->assertContains('session_id', $names);
        $this->assertContains('user_id',    $names);
    }

    public function test_schema_parser_backtick_primary_key_not_nullable(): void
    {
        $tables = (new SchemaParser())->parse(
            'CREATE TABLE `items` (`id` INT AUTO_INCREMENT PRIMARY KEY, `name` VARCHAR(100) NOT NULL);'
        );

        $idCol = $tables[0]->columns[0];
        $this->assertFalse($idCol->nullable, 'PRIMARY KEY columns must not be nullable');
    }

    // =========================================================================
    // Bug 4 — DEFAULT with escaped apostrophe in value
    // =========================================================================

    public function test_schema_parser_default_with_apostrophe(): void
    {
        $tables = (new SchemaParser())->parse(
            "CREATE TABLE settings (id INT PRIMARY KEY, value VARCHAR(255) NOT NULL DEFAULT 'it''s ok', mode VARCHAR(10));"
        );

        $valueCol = $tables[0]->columns[1];
        $this->assertSame("it's ok", $valueCol->default);
    }

    public function test_schema_parser_subsequent_column_after_quoted_default(): void
    {
        // The column after a DEFAULT with commas inside quotes must still be parsed
        $tables = (new SchemaParser())->parse(
            "CREATE TABLE t (id INT PRIMARY KEY, val VARCHAR(50) DEFAULT 'a,b,c', mode VARCHAR(10) NOT NULL);"
        );

        $this->assertCount(3, $tables[0]->columns);
        $this->assertSame('mode', $tables[0]->columns[2]->name);
        $this->assertFalse($tables[0]->columns[2]->nullable);
    }

    // =========================================================================
    // Bug 5 — Overlapping @embed prefixes: longer prefix wins
    // =========================================================================

    public function test_overlapping_embed_prefix_longer_wins(): void
    {
        $schema = <<<SQL
            CREATE TABLE users     (id INT PRIMARY KEY, email VARCHAR(100) NOT NULL);
            CREATE TABLE roles     (id SMALLINT PRIMARY KEY, name VARCHAR(50) NOT NULL);
            CREATE TABLE role_types(id SMALLINT PRIMARY KEY, label VARCHAR(50) NOT NULL);
        SQL;

        $q = $this->analyze($schema,
            "-- @name Get\n-- @returns :one\n-- @embed Role role_\n-- @embed RoleType role_type_\n" .
            "SELECT users.id, roles.name AS role_name, role_types.label AS role_type_label\n" .
            "FROM users JOIN roles ON roles.id=users.id JOIN role_types ON role_types.id=roles.id\n" .
            "WHERE users.id = :id;"
        );

        $dg = new ResultDtoGenerator('App');
        $result = $dg->generate($q[0]);

        // role_type_label should be in RoleType embed, NOT in Role embed
        $this->assertArrayHasKey('RoleType', $result['embeds']);
        $this->assertArrayHasKey('Role',     $result['embeds']);
        $this->assertStringContainsString("\$row['role_type_label']", $result['embeds']['RoleType']['code']);
        $this->assertStringNotContainsString("\$row['role_type_label']", $result['embeds']['Role']['code']);
    }

    public function test_overlapping_embed_short_prefix_gets_remaining_columns(): void
    {
        $schema = <<<SQL
            CREATE TABLE users     (id INT PRIMARY KEY, email VARCHAR(100) NOT NULL);
            CREATE TABLE roles     (id SMALLINT PRIMARY KEY, name VARCHAR(50) NOT NULL);
            CREATE TABLE role_types(id SMALLINT PRIMARY KEY, label VARCHAR(50) NOT NULL);
        SQL;

        $q = $this->analyze($schema,
            "-- @name Get\n-- @returns :one\n-- @embed Role role_\n-- @embed RoleType role_type_\n" .
            "SELECT users.id, roles.name AS role_name, role_types.label AS role_type_label\n" .
            "FROM users JOIN roles ON roles.id=users.id JOIN role_types ON role_types.id=roles.id\n" .
            "WHERE users.id = :id;"
        );

        $dg     = new ResultDtoGenerator('App');
        $result = $dg->generate($q[0]);

        // role_name should be in Role, not in RoleType
        $this->assertStringContainsString("\$row['role_name']", $result['embeds']['Role']['code']);
        $this->assertStringNotContainsString("\$row['role_name']", $result['embeds']['RoleType']['code']);
    }

    // =========================================================================
    // Bug 6 — BETWEEN with @optional throws as unsafe
    // =========================================================================

    public function test_between_with_optional_throws(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/BETWEEN/');

        $this->analyze(
            'CREATE TABLE users (id INT PRIMARY KEY, age INT NULL);',
            "-- @name Filter\n-- @returns :many\n-- @optional minAge\n" .
            "SELECT * FROM users WHERE age BETWEEN :minAge AND :maxAge;"
        );
    }

    public function test_between_without_optional_does_not_throw(): void
    {
        // BETWEEN without @optional is totally fine
        $q = $this->analyze(
            'CREATE TABLE users (id INT PRIMARY KEY, age INT NULL);',
            "-- @name Filter\n-- @returns :many\nSELECT * FROM users WHERE age BETWEEN :minAge AND :maxAge;"
        );

        $this->assertNotEmpty($q);
    }

    // =========================================================================
    // Bug 7 — @embed + @nillable: all-nullable group makes parent property nullable
    // =========================================================================

    public function test_all_nillable_embed_columns_make_parent_property_nullable(): void
    {
        $schema = <<<SQL
            CREATE TABLE users (id INT PRIMARY KEY, email VARCHAR(100) NOT NULL);
            CREATE TABLE roles (id SMALLINT PRIMARY KEY, name VARCHAR(50) NOT NULL);
        SQL;

        $q = $this->analyze($schema,
            "-- @name GetUser\n-- @returns :one\n-- @embed Role role_\n-- @nillable role_name\n" .
            "SELECT users.id, roles.name AS role_name\n" .
            "FROM users LEFT JOIN roles ON roles.id = users.id WHERE users.id = :id;"
        );

        $dg   = new ResultDtoGenerator('App');
        $code = $dg->generate($q[0])['code'];

        // All columns in Role group are nillable → parent property is ?Role
        $this->assertStringContainsString('?Role $role', $code);
    }

    public function test_all_nillable_embed_from_row_is_conditional(): void
    {
        $schema = <<<SQL
            CREATE TABLE users (id INT PRIMARY KEY, email VARCHAR(100) NOT NULL);
            CREATE TABLE roles (id SMALLINT PRIMARY KEY, name VARCHAR(50) NOT NULL);
        SQL;

        $q = $this->analyze($schema,
            "-- @name GetUser\n-- @returns :one\n-- @embed Role role_\n-- @nillable role_name\n" .
            "SELECT users.id, roles.name AS role_name\n" .
            "FROM users LEFT JOIN roles ON roles.id = users.id WHERE users.id = :id;"
        );

        $dg   = new ResultDtoGenerator('App');
        $code = $dg->generate($q[0])['code'];

        // fromRow should use conditional: isset(...) ? Role::fromRow($row) : null
        $this->assertStringContainsString('isset(', $code);
        $this->assertStringContainsString('Role::fromRow($row)', $code);
        $this->assertStringContainsString(': null', $code);
    }

    public function test_mixed_nillable_embed_columns_keep_parent_property_non_nullable(): void
    {
        $schema = <<<SQL
            CREATE TABLE users (id INT PRIMARY KEY, email VARCHAR(100) NOT NULL);
            CREATE TABLE roles (id SMALLINT PRIMARY KEY, name VARCHAR(50) NOT NULL, desc VARCHAR(100) NULL);
        SQL;

        // role_name is NOT NULL, role_desc is NULL — mixed → parent is not nullable
        $q = $this->analyze($schema,
            "-- @name GetUser\n-- @returns :one\n-- @embed Role role_\n" .
            "SELECT users.id, roles.name AS role_name, roles.desc AS role_desc\n" .
            "FROM users JOIN roles ON roles.id = users.id WHERE users.id = :id;"
        );

        $dg   = new ResultDtoGenerator('App');
        $code = $dg->generate($q[0])['code'];

        $this->assertStringContainsString('public Role $role', $code);
        $this->assertStringNotContainsString('?Role', $code);
    }

    // =========================================================================
    // Bug 8 — First @group wins, subsequent ones ignored
    // =========================================================================

    public function test_first_group_wins(): void
    {
        [,, $parser] = $this->makeEnv('CREATE TABLE users (id INT PRIMARY KEY);');
        $queries = $parser->parse(
            "-- @name GetUser\n-- @group User\n-- @group Admin\n-- @returns :one\nSELECT * FROM users WHERE id = :id;"
        );
        $this->assertSame('User', $queries[0]->group);
    }

    // =========================================================================
    // Bug 9 — PRIMARY KEY implies NOT NULL
    // =========================================================================

    public function test_primary_key_column_is_not_nullable(): void
    {
        $tables = (new SchemaParser())->parse(
            'CREATE TABLE users (id INT AUTO_INCREMENT PRIMARY KEY, email VARCHAR(100) NOT NULL);'
        );
        $this->assertFalse($tables[0]->columns[0]->nullable);
    }

    public function test_auto_increment_column_is_not_nullable(): void
    {
        $tables = (new SchemaParser())->parse(
            'CREATE TABLE users (id INT AUTO_INCREMENT, email VARCHAR(100) NOT NULL);'
        );
        $this->assertFalse($tables[0]->columns[0]->nullable);
    }

    public function test_column_without_not_null_is_nullable(): void
    {
        $tables = (new SchemaParser())->parse(
            'CREATE TABLE users (id INT PRIMARY KEY, bio TEXT);'
        );
        $this->assertTrue($tables[0]->columns[1]->nullable);
    }

    public function test_primary_key_maps_to_non_nullable_int_in_param(): void
    {
        $q = $this->analyze(
            'CREATE TABLE users (id INT AUTO_INCREMENT PRIMARY KEY, email VARCHAR(100) NOT NULL);',
            "-- @name GetUser\n-- @returns :one\nSELECT * FROM users WHERE users.id = :id;"
        );
        $this->assertSame('int', $q[0]->params['id']->phpType);
    }

    // =========================================================================
    // Bug 12 — camelCase boolean prefix resolution: isActive → active
    // =========================================================================

    public function test_is_prefix_resolves_to_column_without_prefix(): void
    {
        $q = $this->analyze(
            'CREATE TABLE users (id INT PRIMARY KEY, active TINYINT NOT NULL);',
            "-- @name Update\n-- @returns :exec\nUPDATE users SET active = :isActive WHERE id = :id;"
        );

        $this->assertArrayHasKey('isActive', $q[0]->params);
        $this->assertSame('int', $q[0]->params['isActive']->phpType);
    }

    public function test_has_prefix_resolves_to_column_without_prefix(): void
    {
        $q = $this->analyze(
            'CREATE TABLE users (id INT PRIMARY KEY, role TINYINT NOT NULL);',
            "-- @name Update\n-- @returns :exec\nUPDATE users SET role = :hasRole WHERE id = :id;"
        );

        $this->assertArrayHasKey('hasRole', $q[0]->params);
        // 'role' is TINYINT → int
        $this->assertSame('int', $q[0]->params['hasRole']->phpType);
    }

    // =========================================================================
    // Bug 16 — @embed without prefix throws
    // =========================================================================

    public function test_embed_without_prefix_throws(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/missing the column prefix/');

        [,, $parser] = $this->makeEnv('CREATE TABLE users (id INT PRIMARY KEY);');
        $parser->parse(
            "-- @name GetUser\n-- @returns :one\n-- @embed Role\nSELECT * FROM users WHERE id = :id;"
        );
    }

    // =========================================================================
    // Bug 17 — @optional in SELECT (before WHERE) throws
    // =========================================================================

    public function test_optional_in_select_throws(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/appears before the WHERE clause/');

        $this->analyze(
            'CREATE TABLE users (id INT PRIMARY KEY, email VARCHAR(100) NOT NULL);',
            "-- @name Filter\n-- @returns :many\n-- @optional email\nSELECT :email AS filter FROM users WHERE id > 0;"
        );
    }

    public function test_optional_without_where_throws(): void
    {
        $this->expectException(\RuntimeException::class);
        // Either "without WHERE" from our new check, or "does not match" from parser
        $this->expectExceptionMessageMatches('/without a WHERE clause|does not match/');

        $this->analyze(
            'CREATE TABLE users (id INT PRIMARY KEY, email VARCHAR(100) NOT NULL);',
            "-- @name Update\n-- @returns :exec\n-- @optional email\nUPDATE users SET email = :email;"
        );
    }

    public function test_optional_in_where_does_not_throw(): void
    {
        $q = $this->analyze(
            'CREATE TABLE users (id INT PRIMARY KEY, email VARCHAR(100) NOT NULL);',
            "-- @name Filter\n-- @returns :many\n-- @optional email\nSELECT * FROM users WHERE email = :email;"
        );
        $this->assertNotEmpty($q);
        $this->assertStringContainsString('IS NULL', $q[0]->sql);
    }
}
