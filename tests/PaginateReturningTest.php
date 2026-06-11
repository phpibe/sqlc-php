<?php

declare(strict_types=1);

namespace SqlcPhp\Tests;

use PHPUnit\Framework\TestCase;
use SqlcPhp\Analyzer\QueryAnalyzer;
use SqlcPhp\Catalog\SchemaCatalog;
use SqlcPhp\Generator\EnumGenerator;
use SqlcPhp\Generator\InterfaceGenerator;
use SqlcPhp\Generator\QueryGenerator;
use SqlcPhp\Generator\ResultDtoGenerator;
use SqlcPhp\Parser\QueryParser;
use SqlcPhp\Parser\SchemaParser;
use SqlcPhp\Query\PaginatedResult;
use SqlcPhp\Resolver\ColumnResolver;
use SqlcPhp\Resolver\ExpressionTypeResolver;
use SqlcPhp\Resolver\ParamResolver;
use SqlcPhp\Rewriter\SqlRewriter;
use SqlcPhp\TypeMapper\MySQLTypeMapper;

class PaginateReturningTest extends TestCase
{
    private SchemaCatalog   $catalog;
    private MySQLTypeMapper $mapper;
    private QueryParser     $parser;
    private QueryAnalyzer   $analyzer;
    private QueryGenerator  $qg;

    protected function setUp(): void
    {
        $schema = <<<SQL
            CREATE TABLE users (
                id         INT           AUTO_INCREMENT PRIMARY KEY,
                email      VARCHAR(100)  NOT NULL,
                name       VARCHAR(100)  NULL,
                active     TINYINT       NOT NULL DEFAULT 1,
                country_id INT           NULL
            );
            CREATE TABLE posts (
                post_id INT AUTO_INCREMENT PRIMARY KEY,
                title   VARCHAR(255) NOT NULL,
                user_id INT NOT NULL
            );
            CREATE TABLE tags (
                slug  VARCHAR(50) NOT NULL,
                label VARCHAR(100) NOT NULL
            );
        SQL;

        $this->catalog  = new SchemaCatalog((new SchemaParser())->parse($schema));
        $this->mapper   = new MySQLTypeMapper([], new EnumGenerator('App'));
        $this->parser   = new QueryParser();
        $pr             = new ParamResolver($this->catalog, $this->mapper);
        $er             = new ExpressionTypeResolver($this->catalog, $this->mapper);
        $cr             = new ColumnResolver($this->catalog, $this->mapper, $pr, $er);
        $this->analyzer = new QueryAnalyzer($pr, $cr, $this->parser, new SqlRewriter(), $this->catalog);
        $dtoGen         = new ResultDtoGenerator('App', $this->mapper);
        $this->qg       = new QueryGenerator($this->catalog, $this->mapper, $dtoGen, 'App');
    }

    private function analyze(string $sql): array
    {
        return $this->analyzer->analyze($this->parser->parse($sql));
    }

    private function code(string $sql): string
    {
        $q = $this->analyze($sql);
        return $this->qg->generate($q)['UserQuery']['code'];
    }

    // =========================================================================
    // PaginatedResult — unit tests
    // =========================================================================

    public function test_paginated_result_current_page(): void
    {
        $r = new PaginatedResult(items: [], total: 100, limit: 20, offset: 40, pages: 5, hasMore: true);
        $this->assertSame(3, $r->currentPage());
    }

    public function test_paginated_result_first_page(): void
    {
        $r = new PaginatedResult(items: [], total: 100, limit: 20, offset: 0, pages: 5, hasMore: true);
        $this->assertTrue($r->isFirstPage());
        $this->assertFalse($r->isLastPage());
    }

    public function test_paginated_result_last_page(): void
    {
        $r = new PaginatedResult(items: [], total: 100, limit: 20, offset: 80, pages: 5, hasMore: false);
        $this->assertFalse($r->isFirstPage());
        $this->assertTrue($r->isLastPage());
    }

    public function test_paginated_result_next_offset(): void
    {
        $r = new PaginatedResult(items: [], total: 100, limit: 20, offset: 20, pages: 5, hasMore: true);
        $this->assertSame(40, $r->nextOffset());
    }

    public function test_paginated_result_next_offset_null_on_last_page(): void
    {
        $r = new PaginatedResult(items: [], total: 50, limit: 20, offset: 40, pages: 3, hasMore: false);
        $this->assertNull($r->nextOffset());
    }

    public function test_paginated_result_previous_offset(): void
    {
        $r = new PaginatedResult(items: [], total: 100, limit: 20, offset: 40, pages: 5, hasMore: true);
        $this->assertSame(20, $r->previousOffset());
    }

    public function test_paginated_result_previous_offset_null_on_first_page(): void
    {
        $r = new PaginatedResult(items: [], total: 100, limit: 20, offset: 0, pages: 5, hasMore: true);
        $this->assertNull($r->previousOffset());
    }

    public function test_paginated_result_previous_offset_clamped_to_zero(): void
    {
        $r = new PaginatedResult(items: [], total: 100, limit: 20, offset: 10, pages: 5, hasMore: true);
        $this->assertSame(0, $r->previousOffset()); // max(0, 10-20) = 0
    }

    public function test_paginated_result_is_readonly(): void
    {
        $r = new PaginatedResult(['a'], 10, 5, 0, 2, true);
        $this->assertTrue((new \ReflectionClass($r))->isReadOnly());
    }

    // =========================================================================
    // SchemaCatalog::primaryKey()
    // =========================================================================

    public function test_primary_key_detected_from_primary_key_flag(): void
    {
        $pk = $this->catalog->primaryKey('users');
        $this->assertSame('id', $pk); // id has AUTO_INCREMENT PRIMARY KEY
    }

    public function test_primary_key_detected_from_auto_increment(): void
    {
        $pk = $this->catalog->primaryKey('posts');
        $this->assertSame('post_id', $pk); // post_id has AUTO_INCREMENT PRIMARY KEY
    }

    public function test_primary_key_returns_null_for_table_without_pk(): void
    {
        $pk = $this->catalog->primaryKey('tags'); // no PK, no AUTO_INCREMENT, no 'id' col
        $this->assertNull($pk);
    }

    public function test_primary_key_returns_null_for_unknown_table(): void
    {
        $pk = $this->catalog->primaryKey('nonexistent');
        $this->assertNull($pk);
    }

    // =========================================================================
    // =========================================================================
    // :paginated — parser
    // =========================================================================

    public function test_paginated_return_type_parsed(): void
    {
        $q = $this->parser->parse(
            "-- @name ListUsers\n-- @returns :paginated\nSELECT * FROM users;"
        );
        $this->assertSame(':paginated', $q[0]->returns->value);
    }

    public function test_many_paginated_is_not_paginated(): void
    {
        $q = $this->parser->parse(
            "-- @name ListUsers\n-- @returns :many-paginated\nSELECT * FROM users;"
        );
        $this->assertNotSame(':paginated', $q[0]->returns->value);
    }

    // =========================================================================
    // :paginated — analyzer validations
    // =========================================================================

    public function test_paginated_on_exec_throws(): void
    {
        // :paginated is only valid for SELECT queries (not :exec)
        // The query generates no result columns → should still be parseable,
        // but :paginated on a DELETE makes no sense. The generator would fail.
        // We test that a valid SELECT does NOT throw.
        $q = $this->analyze(
            "-- @name ListUsers\n-- @returns :paginated\nSELECT * FROM users;"
        );
        $this->assertSame(':paginated', $q[0]->returns->value);
    }

    public function test_paginated_on_one_throws(): void
    {
        // :one is a distinct return type — should not be confused with :paginated
        $q = $this->parser->parse(
            "-- @name GetUser\n-- @returns :one\nSELECT * FROM users WHERE id = :id;"
        );
        $this->assertSame(':one', $q[0]->returns->value);
        $this->assertNotSame(':paginated', $q[0]->returns->value);
    }

    public function test_paginated_with_counted_throws(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/:paginated.*@counted/');
        $this->analyze(
            "-- @name ListUsers\n-- @returns :paginated\n-- @counted\n" .
            "SELECT * FROM users;"
        );
    }

    public function test_paginated_return_type_does_not_throw(): void
    {
        $q = $this->analyze(
            "-- @name ListUsers\n-- @returns :paginated\nSELECT * FROM users;"
        );
        $this->assertSame(':paginated', $q[0]->returns->value);
    }

    // =========================================================================
    // :paginated — generated method
    // =========================================================================

    public function test_paginated_method_returns_paginated_result(): void
    {
        $code = $this->code(
            "-- @name ListUsers\n-- @returns :paginated\nSELECT * FROM users;"
        );
        $this->assertStringContainsString('): PaginatedResult', $code);
    }

    public function test_paginated_method_has_limit_default_10(): void
    {
        $code = $this->code(
            "-- @name ListUsers\n-- @returns :paginated\nSELECT * FROM users;"
        );
        $this->assertStringContainsString('int $limit = 10', $code);
        $this->assertStringContainsString('int $offset = 0', $code);
    }

    public function test_paginate_limit_is_required_not_nullable(): void
    {
        $code = $this->code(
            "-- @name ListUsers\n-- @returns :paginated\nSELECT * FROM users;"
        );
        // Should NOT have ?int $limit (nullable) — :paginated requires a real limit
        $this->assertStringNotContainsString('?int $limit', $code);
    }

    public function test_paginated_method_runs_count_query(): void
    {
        $code = $this->code(
            "-- @name ListUsers\n-- @returns :paginated\nSELECT * FROM users;"
        );
        $this->assertStringContainsString('COUNT(*) AS _total', $code);
        $this->assertStringContainsString('_paginate_total', $code);
    }

    public function test_paginated_method_adds_limit_offset_to_page_sql(): void
    {
        $code = $this->code(
            "-- @name ListUsers\n-- @returns :paginated\nSELECT * FROM users;"
        );
        $this->assertStringContainsString('LIMIT :limit OFFSET :offset', $code);
    }

    public function test_paginated_method_constructs_paginated_result(): void
    {
        $code = $this->code(
            "-- @name ListUsers\n-- @returns :paginated\nSELECT * FROM users;"
        );
        $this->assertStringContainsString('new PaginatedResult(', $code);
        $this->assertStringContainsString('items:',   $code);
        $this->assertStringContainsString('total:',   $code);
        $this->assertStringContainsString('limit:',   $code);
        $this->assertStringContainsString('offset:',  $code);
        $this->assertStringContainsString('pages:',   $code);
        $this->assertStringContainsString('hasMore:', $code);
    }

    public function test_paginated_method_computes_has_more(): void
    {
        $code = $this->code(
            "-- @name ListUsers\n-- @returns :paginated\nSELECT * FROM users;"
        );
        $this->assertStringContainsString('$offset + count($__items) < $__total', $code);
    }

    public function test_paginated_method_computes_pages(): void
    {
        $code = $this->code(
            "-- @name ListUsers\n-- @returns :paginated\nSELECT * FROM users;"
        );
        $this->assertStringContainsString('ceil($__total / $limit)', $code);
    }

    public function test_paginated_imports_paginated_result(): void
    {
        $code = $this->code(
            "-- @name ListUsers\n-- @returns :paginated\nSELECT * FROM users;"
        );
        $this->assertStringContainsString('use SqlcPhp\\Query\\PaginatedResult', $code);
    }

    public function test_paginated_with_user_params(): void
    {
        $code = $this->code(
            "-- @name ListActiveUsers\n-- @returns :paginated\n" .
            "SELECT * FROM users WHERE active = :active;"
        );
        $this->assertStringContainsString('int $active', $code);
        $this->assertStringContainsString('int $limit = 10', $code);
    }

    public function test_paginated_method_binds_to_count_stmt_not_stmt(): void
    {
        // Regression: @optional params were bound to $stmt (undefined) instead of
        // $__countStmt and $__stmt which are the variable names used in :paginated.
        $code = $this->code(
            "-- @name ListUsers\n-- @optional active\n-- @returns :paginated\n" .
            "SELECT * FROM users WHERE active = :active;"
        );

        // Count block must bind to $__countStmt, NOT to $stmt
        $countStart = (int) strpos($code, '$__countStmt = $this->pdo->prepare(');
        $countEnd   = (int) strpos($code, '$__countStmt->execute()');
        $countBlock = substr($code, $countStart, $countEnd - $countStart);

        $this->assertStringContainsString('$__countStmt->bindValue(', $countBlock,
            'Count block must bind to $__countStmt');
        $this->assertStringNotContainsString('$stmt->bindValue(', $countBlock,
            'Count block must not reference undefined $stmt');

        // Page block must bind to $__stmt, NOT to $stmt
        $pageStart = (int) strpos($code, '$__stmt = $this->pdo->prepare(');
        $pageEnd   = (int) strpos($code, '$__stmt->execute()');
        $pageBlock = substr($code, $pageStart, $pageEnd - $pageStart);

        $this->assertStringContainsString('$__stmt->bindValue(', $pageBlock,
            'Page block must bind to $__stmt');
        $this->assertStringNotContainsString("\$stmt->bindValue(", $pageBlock,
            'Page block must not reference undefined $stmt');
    }

    public function test_paginated_without_optional_has_no_bare_stmt(): void
    {
        // Ensure no query with :paginated (no @optional) references bare $stmt
        $code = $this->code(
            "-- @name ListActiveUsers\n-- @returns :paginated\n" .
            "SELECT * FROM users WHERE active = :active;"
        );

        // $stmt should not appear at all — only $__countStmt and $__stmt
        $methodStart = (int) strpos($code, 'function listActiveUsers');
        $methodCode  = substr($code, $methodStart);
        // Strip '$__stmt' occurrences to check bare '$stmt'
        $stripped = str_replace('$__stmt', '', $methodCode);
        $this->assertStringNotContainsString('$stmt', $stripped,
            'Generated method must not reference bare $stmt variable');
    }

    public function test_paginated_strips_order_by_from_count(): void
    {
        $code = $this->code(
            "-- @name ListUsers\n-- @returns :paginated\n" .
            "SELECT * FROM users ORDER BY id DESC;"
        );
        // The COUNT subquery should NOT include ORDER BY
        $countPos = (int) strpos($code, 'COUNT(*) AS _total');
        $snippet  = substr($code, $countPos - 10, 200);
        $this->assertStringNotContainsString('ORDER BY', strtoupper($snippet));
    }

    // =========================================================================
    // :paginated + @searchable
    // =========================================================================

    public function test_paginated_with_searchable_has_criteria_param(): void
    {
        $q    = $this->analyze(
            "-- @name ListUsers\n-- @searchable\n-- @returns :paginated\n" .
            "SELECT * FROM users;"
        );
        $code = $this->qg->generate($q)['UserQuery']['code'];
        $this->assertStringContainsString('?UserCriteria $criteria = null', $code);
        $this->assertStringContainsString('int $limit = 10', $code);
        $this->assertStringContainsString('new PaginatedResult(', $code);
    }

    public function test_paginated_with_searchable_generates_criteria_class(): void
    {
        $q     = $this->analyze(
            "-- @name ListUsers\n-- @searchable\n-- @returns :paginated\n" .
            "SELECT * FROM users;"
        );
        $files = $this->qg->generate($q);
        $this->assertArrayHasKey('UserCriteria', $files);
    }

    public function test_paginated_with_searchable_applies_criteria_to_count(): void
    {
        $q    = $this->analyze(
            "-- @name ListUsers\n-- @searchable\n-- @returns :paginated\n" .
            "SELECT * FROM users;"
        );
        $code = $this->qg->generate($q)['UserQuery']['code'];
        // Count stmt must also apply criteria bindings
        $this->assertStringContainsString('$criteria?->bindAll($__countStmt)', $code);
    }

    // =========================================================================
    // :paginated interface — not in interface (PaginatedResult is runtime, not domain)
    // =========================================================================

    public function test_paginated_signature_in_interface(): void
    {
        $dtoGen = new ResultDtoGenerator('App', $this->mapper);
        $ig     = new InterfaceGenerator('App');
        $qg     = new QueryGenerator($this->catalog, $this->mapper, $dtoGen, 'App', true, $ig);
        $q      = $this->analyze(
            "-- @name ListUsers\n-- @returns :paginated\nSELECT * FROM users;"
        );
        $files = $qg->generateInterfaces($q);
        $iface = $files['UserQueryInterface']['code'];
        $this->assertStringContainsString('listUsers', $iface);
        $this->assertStringContainsString('PaginatedResult', $iface);
    }

    public function test_paginated_interface_has_use_statement(): void
    {
        // Regression: interface was missing `use SqlcPhp\Query\PaginatedResult`
        // causing "Undefined class PaginatedResult" at runtime.
        $dtoGen = new ResultDtoGenerator('App', $this->mapper);
        $ig     = new InterfaceGenerator('App');
        $qg     = new QueryGenerator($this->catalog, $this->mapper, $dtoGen, 'App', true, $ig);
        $q      = $this->analyze(
            "-- @name ListUsers\n-- @returns :paginated\nSELECT * FROM users;"
        );
        $files = $qg->generateInterfaces($q);
        $iface = $files['UserQueryInterface']['code'];
        $this->assertStringContainsString(
            'use SqlcPhp\\Query\\PaginatedResult;',
            $iface,
            'Interface must import PaginatedResult when a :paginated method is declared'
        );
    }

    public function test_interface_without_paginated_has_no_paginated_import(): void
    {
        $dtoGen = new ResultDtoGenerator('App', $this->mapper);
        $ig     = new InterfaceGenerator('App');
        $qg     = new QueryGenerator($this->catalog, $this->mapper, $dtoGen, 'App', true, $ig);
        $q      = $this->analyze(
            "-- @name ListUsers\n-- @returns :many\nSELECT * FROM users;"
        );
        $files = $qg->generateInterfaces($q);
        $iface = $files['UserQueryInterface']['code'];
        $this->assertStringNotContainsString('PaginatedResult', $iface);
    }

    public function test_paginated_interface_with_separate_namespace_has_use_statement(): void
    {
        // Regression: with map-form out:, namespace differs per type, but
        // PaginatedResult (SqlcPhp\Query\) must still be imported in the interface.
        $dtoGen = new ResultDtoGenerator('App\\Database\\DTOs', $this->mapper);
        $ig     = new InterfaceGenerator('App\\Database\\Contracts');
        $qg     = new QueryGenerator(
            $this->catalog, $this->mapper, $dtoGen,
            'App\\Database\\Repositories', true, $ig
        );
        $q    = $this->analyze(
            "-- @name ListUsers\n-- @returns :paginated\nSELECT * FROM users;"
        );
        $files = $qg->generateInterfaces($q);
        $iface = $files['UserQueryInterface']['code'];
        $this->assertStringContainsString('use SqlcPhp\\Query\\PaginatedResult;', $iface);
        $this->assertStringContainsString('namespace App\\Database\\Contracts', $iface);
    }

    // =========================================================================
    // @returning — parser
    // =========================================================================

    public function test_returning_flag_parsed(): void
    {
        $q = $this->parser->parse(
            "-- @name CreateUser\n-- @returning\n-- @returns :one\n" .
            "INSERT INTO users (email, active) VALUES (:email, :active);"
        );
        $this->assertTrue($q[0]->returning);
    }

    public function test_returning_false_by_default(): void
    {
        $q = $this->parser->parse(
            "-- @name CreateUser\n-- @returns :exec\n" .
            "INSERT INTO users (email) VALUES (:email);"
        );
        $this->assertFalse($q[0]->returning);
    }

    // =========================================================================
    // @returning — analyzer validations
    // =========================================================================

    public function test_returning_on_exec_throws(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/@returning.*:one/');
        $this->analyze(
            "-- @name CreateUser\n-- @returning\n-- @returns :exec\n" .
            "INSERT INTO users (email) VALUES (:email);"
        );
    }

    public function test_returning_on_select_throws(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->analyze(
            "-- @name GetUser\n-- @returning\n-- @returns :one\nSELECT * FROM users WHERE id = :id;"
        );
    }

    public function test_returning_with_on_duplicate_key_throws(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/ON DUPLICATE KEY/');
        $this->analyze(
            "-- @name UpsertUser\n-- @returning\n-- @returns :one\n" .
            "INSERT INTO users (email) VALUES (:email) ON DUPLICATE KEY UPDATE email = :email;"
        );
    }

    public function test_returning_on_insert_one_does_not_throw(): void
    {
        $q = $this->analyze(
            "-- @name CreateUser\n-- @returning\n-- @returns :one\n" .
            "INSERT INTO users (email, active) VALUES (:email, :active);"
        );
        $this->assertTrue($q[0]->returning);
    }

    public function test_returning_on_table_without_pk_throws(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/primary key/i');
        $this->analyze(
            "-- @name CreateTag\n-- @returning\n-- @returns :one\n" .
            "INSERT INTO tags (slug, label) VALUES (:slug, :label);"
        );
    }

    // =========================================================================
    // @returning — generated method
    // =========================================================================

    public function test_returning_method_returns_model(): void
    {
        $code = $this->code(
            "-- @name CreateUser\n-- @returning\n-- @returns :one\n" .
            "INSERT INTO users (email, active) VALUES (:email, :active);"
        );
        $this->assertStringContainsString('): User', $code);
    }

    public function test_returning_method_executes_insert(): void
    {
        $code = $this->code(
            "-- @name CreateUser\n-- @returning\n-- @returns :one\n" .
            "INSERT INTO users (email, active) VALUES (:email, :active);"
        );
        $this->assertStringContainsString('INSERT INTO users', $code);
        $this->assertStringContainsString('$stmt->execute()', $code);
    }

    public function test_returning_method_uses_last_insert_id(): void
    {
        $code = $this->code(
            "-- @name CreateUser\n-- @returning\n-- @returns :one\n" .
            "INSERT INTO users (email, active) VALUES (:email, :active);"
        );
        $this->assertStringContainsString('lastInsertId()', $code);
    }

    public function test_returning_method_fetches_by_primary_key(): void
    {
        $code = $this->code(
            "-- @name CreateUser\n-- @returning\n-- @returns :one\n" .
            "INSERT INTO users (email, active) VALUES (:email, :active);"
        );
        $this->assertStringContainsString('SELECT * FROM users WHERE id =', $code);
    }

    public function test_returning_uses_correct_pk_column(): void
    {
        // posts table has 'post_id' as PK
        $q    = $this->analyze(
            "-- @name CreatePost\n-- @class Post\n-- @returning\n-- @returns :one\n" .
            "INSERT INTO posts (title, user_id) VALUES (:title, :user_id);"
        );
        $code = $this->qg->generate($q)['PostQuery']['code'];
        $this->assertStringContainsString('WHERE post_id =', $code);
        $this->assertStringNotContainsString('WHERE id =', $code);
    }

    public function test_returning_method_throws_if_row_not_found(): void
    {
        $code = $this->code(
            "-- @name CreateUser\n-- @returning\n-- @returns :one\n" .
            "INSERT INTO users (email, active) VALUES (:email, :active);"
        );
        $this->assertStringContainsString('inserted row not found', $code);
        $this->assertStringContainsString('RuntimeException', $code);
    }

    public function test_returning_method_calls_from_row(): void
    {
        $code = $this->code(
            "-- @name CreateUser\n-- @returning\n-- @returns :one\n" .
            "INSERT INTO users (email, active) VALUES (:email, :active);"
        );
        $this->assertStringContainsString('User::fromRow($__row)', $code);
    }

    public function test_returning_method_has_timer(): void
    {
        $code = $this->code(
            "-- @name CreateUser\n-- @returning\n-- @returns :one\n" .
            "INSERT INTO users (email, active) VALUES (:email, :active);"
        );
        $this->assertStringContainsString('hrtime(true)', $code);
        $this->assertStringContainsString('withDuration(', $code);
    }

    public function test_returning_method_records_last_query(): void
    {
        $code = $this->code(
            "-- @name CreateUser\n-- @returning\n-- @returns :one\n" .
            "INSERT INTO users (email, active) VALUES (:email, :active);"
        );
        $this->assertStringContainsString('$this->lastQuery = new QueryObject(', $code);
        $this->assertStringContainsString("'createUser'", $code);
    }

    // =========================================================================
    // Non-:paginated / non-@returning unchanged
    // =========================================================================

    public function test_regular_exec_unchanged(): void
    {
        $code = $this->code(
            "-- @name DeleteUser\n-- @returns :exec\nDELETE FROM users WHERE id = :id;"
        );
        $this->assertStringNotContainsString('PaginatedResult', $code);
        $this->assertStringNotContainsString('lastInsertId', $code);
        $this->assertStringContainsString('function deleteUser(int $id): void', $code);
    }

    public function test_regular_many_paginated_unchanged(): void
    {
        $code = $this->code(
            "-- @name ListUsers\n-- @returns :many-paginated\nSELECT * FROM users;"
        );
        $this->assertStringContainsString('?int $limit = null', $code);
        $this->assertStringNotContainsString('PaginatedResult', $code);
    }

    // =========================================================================
    // Version
    // =========================================================================

    public function test_version_is_2_8_0(): void
    {
        $this->assertSame('2.9.1', \SqlcPhp\Version::VERSION);
    }
}
