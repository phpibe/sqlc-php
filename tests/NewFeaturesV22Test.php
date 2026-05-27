<?php

declare(strict_types=1);

namespace SqlcPhp\Tests;

use PHPUnit\Framework\TestCase;
use SqlcPhp\Analyzer\QueryAnalyzer;
use SqlcPhp\Catalog\SchemaCatalog;
use SqlcPhp\Generator\EnumGenerator;
use SqlcPhp\Generator\QueryGenerator;
use SqlcPhp\Generator\ResultDtoGenerator;
use SqlcPhp\Parser\QueryParser;
use SqlcPhp\Parser\SchemaParser;
use SqlcPhp\Resolver\ColumnResolver;
use SqlcPhp\Resolver\ExpressionTypeResolver;
use SqlcPhp\Resolver\ParamResolver;
use SqlcPhp\Rewriter\SqlRewriter;
use SqlcPhp\TypeMapper\MySQLTypeMapper;
use SqlcPhp\Version;

class NewFeaturesV22Test extends TestCase
{
    private SchemaCatalog   $catalog;
    private QueryParser     $parser;
    private QueryAnalyzer   $analyzer;
    private ResultDtoGenerator $dtoGen;
    private QueryGenerator  $queryGen;

    protected function setUp(): void
    {
        $schema = <<<SQL
            CREATE TABLE users (
                id       INT          AUTO_INCREMENT PRIMARY KEY,
                email    VARCHAR(100) NOT NULL,
                username VARCHAR(50)  NULL,
                active   TINYINT      NOT NULL DEFAULT 1
            );
            CREATE TABLE roles (
                id   SMALLINT     AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(100) NOT NULL
            );
        SQL;

        $this->catalog  = new SchemaCatalog((new SchemaParser())->parse($schema));
        $mapper         = new MySQLTypeMapper([], new EnumGenerator('App'));
        $this->parser   = new QueryParser();
        $pr             = new ParamResolver($this->catalog, $mapper);
        $er             = new ExpressionTypeResolver($this->catalog, $mapper);
        $cr             = new ColumnResolver($this->catalog, $mapper, $pr, $er);
        $this->analyzer = new QueryAnalyzer($pr, $cr, $this->parser, new SqlRewriter());
        $this->dtoGen   = new ResultDtoGenerator('App', $mapper);
        $this->queryGen = new QueryGenerator($this->catalog, $mapper, $this->dtoGen, 'App');
    }

    private function analyze(string $sql): array
    {
        return $this->analyzer->analyze($this->parser->parse($sql));
    }

    // =========================================================================
    // Version
    // =========================================================================

    public function test_version_constant_is_a_semver_string(): void
    {
        $this->assertMatchesRegularExpression('/^\d+\.\d+\.\d+$/', Version::VERSION);
    }

    public function test_version_get_returns_same_as_constant(): void
    {
        $this->assertSame(Version::VERSION, Version::get());
    }

    public function test_version_banner_contains_version(): void
    {
        $this->assertStringContainsString(Version::VERSION, Version::BANNER);
    }

    public function test_version_banner_contains_project_name(): void
    {
        $this->assertStringContainsString('sqlc-php', Version::BANNER);
    }

    // =========================================================================
    // @dto — custom DTO class name
    // =========================================================================

    public function test_dto_annotation_is_parsed(): void
    {
        $queries = $this->parser->parse(
            "-- @name ListUsers\n-- @returns :many\n-- @dto UserProfile\n" .
            "SELECT users.id, roles.name AS role_name FROM users JOIN roles ON roles.id = users.id;"
        );

        $this->assertSame('UserProfile', $queries[0]->dtoClassName);
    }

    public function test_dto_annotation_overrides_generated_name(): void
    {
        $q = $this->analyze(
            "-- @name ListUsers\n-- @returns :many\n-- @dto UserProfile\n" .
            "SELECT users.id, roles.name AS role_name FROM users JOIN roles ON roles.id = users.id;"
        );

        $this->assertSame('UserProfile', $this->dtoGen->dtoClassName($q[0]));
    }

    public function test_without_dto_annotation_uses_generated_name(): void
    {
        $q = $this->analyze(
            "-- @name ListUsers\n-- @returns :many\n" .
            "SELECT users.id, roles.name AS role_name FROM users JOIN roles ON roles.id = users.id;"
        );

        $this->assertSame('ListUsersRow', $this->dtoGen->dtoClassName($q[0]));
    }

    public function test_dto_class_name_is_used_in_generated_code(): void
    {
        $q = $this->analyze(
            "-- @name ListUsers\n-- @returns :many\n-- @dto UserProfile\n" .
            "SELECT users.id, roles.name AS role_name FROM users JOIN roles ON roles.id = users.id;"
        );

        ['code' => $code] = $this->dtoGen->generate($q[0]);

        $this->assertStringContainsString('class UserProfile', $code);
        $this->assertStringNotContainsString('ListUsersRow', $code);
    }

    public function test_dto_name_is_used_in_query_method_return_type(): void
    {
        $q = $this->analyze(
            "-- @name ListUsers\n-- @returns :many\n-- @dto UserProfile\n" .
            "SELECT users.id, roles.name AS role_name FROM users JOIN roles ON roles.id = users.id;"
        );

        $code = $this->queryGen->generate($q)['UserQuery']['code'];

        $this->assertStringContainsString('UserProfile', $code);
        $this->assertStringNotContainsString('ListUsersRow', $code);
    }

    public function test_dto_name_used_in_docblock(): void
    {
        $q = $this->analyze(
            "-- @name ListUsers\n-- @returns :many\n-- @dto UserProfile\n" .
            "SELECT users.id, roles.name AS role_name FROM users JOIN roles ON roles.id = users.id;"
        );

        $code = $this->queryGen->generate($q)['UserQuery']['code'];

        $this->assertStringContainsString('@return UserProfile[]', $code);
    }

    public function test_dto_null_when_annotation_absent(): void
    {
        $queries = $this->parser->parse(
            "-- @name GetUser\n-- @returns :one\nSELECT * FROM users WHERE id = :id;"
        );

        $this->assertNull($queries[0]->dtoClassName);
    }

    public function test_dto_preserved_through_analyzer(): void
    {
        $q = $this->analyze(
            "-- @name GetUser\n-- @returns :one\n-- @dto UserDetail\n" .
            "SELECT users.id, roles.name AS role_name FROM users JOIN roles ON roles.id = users.id " .
            "WHERE users.id = :id;"
        );

        $this->assertSame('UserDetail', $q[0]->dtoClassName);
    }

    // =========================================================================
    // @column — rename result column without AS in SQL
    // =========================================================================

    public function test_column_annotation_is_parsed(): void
    {
        $queries = $this->parser->parse(
            "-- @name GetUser\n-- @returns :one\n-- @column role_name roleName\n" .
            "SELECT users.id, roles.name AS role_name FROM users JOIN roles ON roles.id = users.id " .
            "WHERE users.id = :id;"
        );

        $this->assertArrayHasKey('role_name', $queries[0]->columnAliases);
        $this->assertSame('roleName', $queries[0]->columnAliases['role_name']);
    }

    public function test_column_annotation_renames_result_column(): void
    {
        $q = $this->analyze(
            "-- @name GetUser\n-- @returns :one\n-- @column role_name roleName\n" .
            "SELECT users.id, roles.name AS role_name FROM users JOIN roles ON roles.id = users.id " .
            "WHERE users.id = :id;"
        );

        $aliases = array_map(fn($c) => $c->alias, $q[0]->resultColumns);
        $this->assertContains('roleName', $aliases);
        $this->assertNotContains('role_name', $aliases);
    }

    public function test_column_annotation_generates_correct_property_name(): void
    {
        $q = $this->analyze(
            "-- @name GetUser\n-- @returns :one\n-- @column role_name roleName\n" .
            "SELECT users.id, roles.name AS role_name FROM users JOIN roles ON roles.id = users.id " .
            "WHERE users.id = :id;"
        );

        ['code' => $code] = $this->dtoGen->generate($q[0]);

        $this->assertStringContainsString('$roleName', $code);
        $this->assertStringNotContainsString('$role_name', $code);
    }

    public function test_column_annotation_uses_renamed_key_in_from_row(): void
    {
        $q = $this->analyze(
            "-- @name GetUser\n-- @returns :one\n-- @column role_name roleName\n" .
            "SELECT users.id, roles.name AS role_name FROM users JOIN roles ON roles.id = users.id " .
            "WHERE users.id = :id;"
        );

        ['code' => $code] = $this->dtoGen->generate($q[0]);

        // fromRow must use the renamed key since that's what the DTO property is named
        $this->assertStringContainsString("\$row['roleName']", $code);
    }

    public function test_multiple_column_annotations(): void
    {
        $q = $this->analyze(
            "-- @name GetUser\n-- @returns :one\n-- @column role_name roleName\n-- @column id userId\n" .
            "SELECT users.id, roles.name AS role_name FROM users JOIN roles ON roles.id = users.id " .
            "WHERE users.id = :id;"
        );

        $aliases = array_map(fn($c) => $c->alias, $q[0]->resultColumns);
        $this->assertContains('roleName', $aliases);
        $this->assertContains('userId',   $aliases);
        $this->assertNotContains('id',        $aliases);
        $this->assertNotContains('role_name', $aliases);
    }

    public function test_column_annotation_empty_when_no_declaration(): void
    {
        $queries = $this->parser->parse(
            "-- @name GetUser\n-- @returns :one\nSELECT * FROM users WHERE id = :id;"
        );

        $this->assertSame([], $queries[0]->columnAliases);
    }

    public function test_column_annotation_preserves_unaffected_columns(): void
    {
        $q = $this->analyze(
            "-- @name GetUser\n-- @returns :one\n-- @column role_name roleName\n" .
            "SELECT users.id, roles.name AS role_name FROM users JOIN roles ON roles.id = users.id " .
            "WHERE users.id = :id;"
        );

        $aliases = array_map(fn($c) => $c->alias, $q[0]->resultColumns);
        // 'id' not renamed — stays as 'id'
        $this->assertContains('id', $aliases);
    }

    public function test_column_annotation_with_select_star_query(): void
    {
        $q = $this->analyze(
            "-- @name GetUser\n-- @returns :one\n-- @column email emailAddress\n" .
            "SELECT * FROM users WHERE id = :id;"
        );

        // @column forces a custom DTO (same as @nillable on direct model)
        $aliases = array_map(fn($c) => $c->alias, $q[0]->resultColumns);
        $this->assertContains('emailAddress', $aliases);
        $this->assertNotContains('email', $aliases);
    }

    // =========================================================================
    // @dto + @column combined
    // =========================================================================

    public function test_dto_and_column_annotations_work_together(): void
    {
        $q = $this->analyze(
            "-- @name ListUsers\n-- @returns :many\n-- @dto UserProfile\n-- @column role_name roleName\n" .
            "SELECT users.id, roles.name AS role_name FROM users JOIN roles ON roles.id = users.id;"
        );

        $this->assertSame('UserProfile', $this->dtoGen->dtoClassName($q[0]));
        $aliases = array_map(fn($c) => $c->alias, $q[0]->resultColumns);
        $this->assertContains('roleName', $aliases);

        ['code' => $code] = $this->dtoGen->generate($q[0]);
        $this->assertStringContainsString('class UserProfile', $code);
        $this->assertStringContainsString('$roleName', $code);
    }
}
