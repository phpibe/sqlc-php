<?php

declare(strict_types=1);

namespace SqlcPhp\Tests\Analyzer;

use PHPUnit\Framework\TestCase;
use SqlcPhp\Analyzer\QueryAnalyzer;
use SqlcPhp\Catalog\SchemaCatalog;
use SqlcPhp\Parser\QueryParser;
use SqlcPhp\Parser\ReturnType;
use SqlcPhp\Parser\SchemaParser;
use SqlcPhp\Resolver\ColumnResolver;
use SqlcPhp\Resolver\ExpressionTypeResolver;
use SqlcPhp\Resolver\ParamResolver;
use SqlcPhp\TypeMapper\MySQLTypeMapper;

class QueryAnalyzerTest extends TestCase
{
    private QueryAnalyzer $analyzer;
    private QueryParser   $queryParser;

    protected function setUp(): void
    {
        $schema = <<<SQL
            CREATE TABLE users (
                id         INT          AUTO_INCREMENT PRIMARY KEY,
                email      VARCHAR(100) NOT NULL,
                username   VARCHAR(45)  null,
                active     TINYINT      DEFAULT 1 null,
                role_id    SMALLINT     NOT NULL,
                created_at TIMESTAMP    null
            );
            CREATE TABLE roles (
                id          SMALLINT     AUTO_INCREMENT PRIMARY KEY,
                name        VARCHAR(100) NOT NULL,
                description VARCHAR(255) null
            );
        SQL;

        $catalog          = new SchemaCatalog((new SchemaParser())->parse($schema));
        $mapper           = new MySQLTypeMapper();
        $this->queryParser = new QueryParser();
        $paramResolver    = new ParamResolver($catalog, $mapper);
        $exprResolver     = new ExpressionTypeResolver($catalog, $mapper);
        $colResolver      = new ColumnResolver($catalog, $mapper, $paramResolver, $exprResolver);

        $this->analyzer = new QueryAnalyzer($paramResolver, $colResolver, $this->queryParser);
    }

    private function analyze(string $sql): array
    {
        return $this->analyzer->analyze($this->queryParser->parse($sql));
    }

    // -------------------------------------------------------------------------
    // :many — SELECT *
    // -------------------------------------------------------------------------

    public function test_select_star_returns_model_directly(): void
    {
        $queries = $this->analyze("-- @name List\n-- @returns :many\nSELECT users.* FROM users;");
        $this->assertTrue($queries[0]->returnsModelDirectly);
        $this->assertSame('User', $queries[0]->modelClass);
    }

    public function test_select_star_many_has_all_columns(): void
    {
        $queries = $this->analyze("-- @name List\n-- @returns :many\nSELECT users.* FROM users;");
        $this->assertCount(6, $queries[0]->resultColumns);
    }

    // -------------------------------------------------------------------------
    // :one — throws, non-nullable return
    // -------------------------------------------------------------------------

    public function test_one_returns_model_directly(): void
    {
        $queries = $this->analyze(
            "-- @name Get\n-- @returns :one\nSELECT * FROM users WHERE users.id = :id;"
        );
        $this->assertTrue($queries[0]->returnsModelDirectly);
        $this->assertSame('User', $queries[0]->modelClass);
    }

    public function test_one_resolves_id_param_as_int(): void
    {
        $queries = $this->analyze(
            "-- @name Get\n-- @returns :one\nSELECT * FROM users WHERE users.id = :id;"
        );
        $this->assertArrayHasKey('id', $queries[0]->params);
        $this->assertSame('?int', $queries[0]->params['id']->phpType);
    }

    // -------------------------------------------------------------------------
    // :opt — nullable return
    // -------------------------------------------------------------------------

    public function test_opt_returns_model_directly(): void
    {
        $queries = $this->analyze(
            "-- @name Find\n-- @returns :opt\nSELECT * FROM users WHERE users.email = :email;"
        );
        $this->assertTrue($queries[0]->returnsModelDirectly);
    }

    public function test_opt_resolves_email_param_as_string(): void
    {
        $queries = $this->analyze(
            "-- @name Find\n-- @returns :opt\nSELECT * FROM users WHERE users.email = :email;"
        );
        $this->assertSame('string', $queries[0]->params['email']->phpType);
    }

    // -------------------------------------------------------------------------
    // :exec — no result columns
    // -------------------------------------------------------------------------

    public function test_exec_has_no_result_columns(): void
    {
        $queries = $this->analyze(
            "-- @name Del\n-- @returns :exec\nDELETE FROM users WHERE id = :id;"
        );
        $this->assertEmpty($queries[0]->resultColumns);
    }

    public function test_exec_resolves_param(): void
    {
        $queries = $this->analyze(
            "-- @name Del\n-- @returns :exec\nDELETE FROM users WHERE id = :id;"
        );
        $this->assertArrayHasKey('id', $queries[0]->params);
    }

    // -------------------------------------------------------------------------
    // SELECT specific columns — still direct model (single table)
    // -------------------------------------------------------------------------

    public function test_partial_select_from_single_table_returns_model(): void
    {
        $queries = $this->analyze(
            "-- @name Profile\n-- @returns :one\nSELECT users.id, users.email FROM users WHERE users.id = :id;"
        );
        $this->assertTrue($queries[0]->returnsModelDirectly);
        $this->assertSame('User', $queries[0]->modelClass);
    }

    // -------------------------------------------------------------------------
    // JOIN — custom DTO needed
    // -------------------------------------------------------------------------

    public function test_join_query_does_not_return_model_directly(): void
    {
        $queries = $this->analyze(<<<SQL
            -- @name GetWithRole
            -- @returns :one
            SELECT users.id, users.email, roles.name AS role_name
            FROM users
            INNER JOIN roles ON roles.id = users.role_id
            WHERE users.id = :id;
        SQL);

        $this->assertFalse($queries[0]->returnsModelDirectly);
        $this->assertNull($queries[0]->modelClass);
    }

    public function test_join_query_resolves_columns_from_both_tables(): void
    {
        $queries = $this->analyze(<<<SQL
            -- @name GetWithRole
            -- @returns :one
            SELECT users.id, users.email, roles.name AS role_name
            FROM users
            INNER JOIN roles ON roles.id = users.role_id
            WHERE users.id = :id;
        SQL);

        $aliases = array_map(fn($c) => $c->alias, $queries[0]->resultColumns);
        $this->assertContains('id',        $aliases);
        $this->assertContains('email',     $aliases);
        $this->assertContains('role_name', $aliases);
    }

    // -------------------------------------------------------------------------
    // Aggregate expressions
    // -------------------------------------------------------------------------

    public function test_count_star_resolves_to_int(): void
    {
        $queries = $this->analyze(
            "-- @name Stats\n-- @returns :one\nSELECT COUNT(*) AS total FROM users;"
        );

        $col = $queries[0]->resultColumns[0];
        $this->assertSame('total', $col->alias);
        $this->assertSame('int', $col->phpType);
    }

    public function test_avg_resolves_to_nullable_float(): void
    {
        $queries = $this->analyze(
            "-- @name Stats\n-- @returns :one\nSELECT AVG(role_id) AS avg_role FROM users;"
        );

        $col = $queries[0]->resultColumns[0];
        $this->assertSame('avg_role', $col->alias);
        $this->assertSame('?float', $col->phpType);
    }

    public function test_expression_without_alias_gets_generated_name(): void
    {
        $queries = $this->analyze(
            "-- @name Stats\n-- @returns :one\nSELECT COUNT(*) FROM users;"
        );

        $col = $queries[0]->resultColumns[0];
        $this->assertSame('count', $col->alias);
    }

    public function test_unknown_expression_gets_positional_name(): void
    {
        $queries = $this->analyze(
            "-- @name Stats\n-- @returns :one\nSELECT SOME_UNKNOWN(x) FROM users;"
        );

        $col = $queries[0]->resultColumns[0];
        $this->assertSame('col_1', $col->alias);
    }
}
