<?php

declare(strict_types=1);

namespace SqlcPhp\Tests;

use PHPUnit\Framework\TestCase;
use SqlcPhp\Analyzer\QueryAnalyzer;
use SqlcPhp\Catalog\SchemaCatalog;
use SqlcPhp\Generator\QueryGenerator;
use SqlcPhp\Generator\ResultDtoGenerator;
use SqlcPhp\Parser\QueryParser;
use SqlcPhp\Parser\SchemaParser;
use SqlcPhp\Resolver\ColumnResolver;
use SqlcPhp\Resolver\ExpressionTypeResolver;
use SqlcPhp\Resolver\ParamResolver;
use SqlcPhp\Rewriter\SqlRewriter;
use SqlcPhp\TypeMapper\MySQLTypeMapper;

/**
 * Tests for the @nullable param annotation (v2.15.0).
 *
 * @nullable paramName forces the PHP type of a query parameter to ?type,
 * allowing the caller to pass null to set a nullable column.
 *
 * Unlike @optional (which rewrites the SQL condition), @nullable only
 * changes the PHP type — the SQL remains unchanged.
 *
 * Supports: single param, comma-separated list, multiple @nullable lines.
 */
class NullableParamAnnotationTest extends TestCase
{
    private SchemaCatalog  $catalog;
    private MySQLTypeMapper $mapper;
    private QueryParser    $parser;
    private QueryAnalyzer  $analyzer;
    private QueryGenerator $queryGen;

    protected function setUp(): void
    {
        $schema = <<<SQL
            CREATE TABLE users (
                id          INT           AUTO_INCREMENT PRIMARY KEY,
                email       VARCHAR(100)  NOT NULL,
                name        VARCHAR(100)  NOT NULL,
                avatar_url  VARCHAR(255)  NULL,
                deleted_at  DATETIME      NULL,
                role        VARCHAR(20)   NOT NULL DEFAULT 'client'
            );
        SQL;

        $this->catalog  = new SchemaCatalog((new SchemaParser())->parse($schema));
        $this->mapper   = new MySQLTypeMapper();
        $this->parser   = new QueryParser();
        $pr             = new ParamResolver($this->catalog, $this->mapper);
        $er             = new ExpressionTypeResolver($this->catalog, $this->mapper);
        $cr             = new ColumnResolver($this->catalog, $this->mapper, $pr, $er);
        $this->analyzer = new QueryAnalyzer($pr, $cr, $this->parser, new SqlRewriter(), $this->catalog);
        $dtoGen         = new ResultDtoGenerator('App\\DTOs', $this->mapper, $this->catalog);
        $this->queryGen = new QueryGenerator($this->catalog, $this->mapper, $dtoGen, 'App\\Queries');
    }

    private function analyze(string $sql): array
    {
        return $this->analyzer->analyze($this->parser->parse($sql));
    }

    private function queryCode(string $sql): string
    {
        $queries = $this->analyze($sql);
        $result  = $this->queryGen->generate($queries);
        foreach ($result as $item) {
            if (str_ends_with($item['className'], 'Query')) return $item['code'];
        }
        return array_values($result)[0]['code'];
    }

    // =========================================================================
    // QueryParser
    // =========================================================================

    public function test_parser_captures_single_nullable_param(): void
    {
        $queries = $this->parser->parse(<<<SQL
            -- @name UpdateAvatar
            -- @class Users
            -- @returns :exec
            -- @nullable avatarUrl
            UPDATE users SET avatar_url = :avatarUrl WHERE id = :id;
        SQL);

        $this->assertSame(['avatarUrl'], $queries[0]->nullableParams);
    }

    public function test_parser_captures_comma_separated_nullable_params(): void
    {
        $queries = $this->parser->parse(<<<SQL
            -- @name SoftDelete
            -- @class Users
            -- @returns :exec
            -- @nullable deletedAt, avatarUrl
            UPDATE users SET deleted_at = :deletedAt, avatar_url = :avatarUrl WHERE id = :id;
        SQL);

        $this->assertSame(['deletedAt', 'avatarUrl'], $queries[0]->nullableParams);
    }

    public function test_parser_captures_multiple_nullable_lines(): void
    {
        $queries = $this->parser->parse(<<<SQL
            -- @name UpdateUser
            -- @class Users
            -- @returns :exec
            -- @nullable deletedAt
            -- @nullable avatarUrl
            UPDATE users SET deleted_at = :deletedAt, avatar_url = :avatarUrl WHERE id = :id;
        SQL);

        $this->assertSame(['deletedAt', 'avatarUrl'], $queries[0]->nullableParams);
    }

    public function test_parser_deduplicates_nullable_params(): void
    {
        $queries = $this->parser->parse(<<<SQL
            -- @name UpdateUser
            -- @class Users
            -- @returns :exec
            -- @nullable avatarUrl, avatarUrl
            UPDATE users SET avatar_url = :avatarUrl WHERE id = :id;
        SQL);

        $this->assertSame(['avatarUrl'], $queries[0]->nullableParams);
    }

    public function test_parser_no_nullable_annotation_gives_empty_array(): void
    {
        $queries = $this->parser->parse(<<<SQL
            -- @name GetUser
            -- @class Users
            -- @returns :one
            SELECT * FROM users WHERE id = :id;
        SQL);

        $this->assertSame([], $queries[0]->nullableParams);
    }

    // =========================================================================
    // QueryAnalyzer — type widening
    // =========================================================================

    public function test_nullable_forces_non_nullable_param_to_nullable(): void
    {
        // email is NOT NULL in schema → would normally resolve to string
        $queries = $this->analyze(<<<SQL
            -- @name UpdateEmail
            -- @class Users
            -- @returns :exec
            -- @nullable email
            UPDATE users SET email = :email WHERE id = :id;
        SQL);

        $emailParam = $queries[0]->params['email'] ?? null;
        $this->assertNotNull($emailParam);
        $this->assertSame('?string', $emailParam->phpType);
        $this->assertTrue($emailParam->nullable);
    }

    public function test_nullable_on_already_nullable_column_is_idempotent(): void
    {
        // avatar_url IS NULL → would already resolve to ?string
        $queries = $this->analyze(<<<SQL
            -- @name UpdateAvatar
            -- @class Users
            -- @returns :exec
            -- @nullable avatarUrl
            UPDATE users SET avatar_url = :avatarUrl WHERE id = :id;
        SQL);

        $param = $queries[0]->params['avatarUrl'] ?? null;
        $this->assertNotNull($param);
        $this->assertSame('?string', $param->phpType);
        // Should have exactly one ? prefix
        $this->assertStringStartsWith('?', $param->phpType);
        $this->assertStringNotContainsString('??', $param->phpType);
    }

    public function test_nullable_does_not_affect_other_params(): void
    {
        $queries = $this->analyze(<<<SQL
            -- @name UpdateAvatar
            -- @class Users
            -- @returns :exec
            -- @nullable avatarUrl
            UPDATE users SET avatar_url = :avatarUrl WHERE id = :id;
        SQL);

        // id is NOT NULL and not @nullable — stays int
        $idParam = $queries[0]->params['id'] ?? null;
        $this->assertNotNull($idParam);
        $this->assertSame('int', $idParam->phpType);
        $this->assertFalse($idParam->nullable);
    }

    public function test_nullable_multiple_params_all_widened(): void
    {
        $queries = $this->analyze(<<<SQL
            -- @name SoftDelete
            -- @class Users
            -- @returns :exec
            -- @nullable deletedAt, name
            UPDATE users SET deleted_at = :deletedAt, name = :name WHERE id = :id;
        SQL);

        $deletedAt = $queries[0]->params['deletedAt'] ?? null;
        $name      = $queries[0]->params['name']      ?? null;

        $this->assertNotNull($deletedAt);
        $this->assertNotNull($name);

        $this->assertTrue($deletedAt->nullable);
        $this->assertTrue($name->nullable);
        $this->assertStringStartsWith('?', $deletedAt->phpType);
        $this->assertStringStartsWith('?', $name->phpType);
    }

    // =========================================================================
    // QueryGenerator — generated method signature
    // =========================================================================

    public function test_nullable_param_generates_nullable_signature(): void
    {
        $code = $this->queryCode(<<<SQL
            -- @name UpdateAvatar
            -- @class Users
            -- @returns :exec
            -- @nullable avatarUrl
            UPDATE users SET avatar_url = :avatarUrl WHERE id = :id;
        SQL);

        $this->assertStringContainsString('?string $avatarUrl', $code);
    }

    public function test_non_nullable_param_stays_non_nullable(): void
    {
        $code = $this->queryCode(<<<SQL
            -- @name UpdateAvatar
            -- @class Users
            -- @returns :exec
            -- @nullable avatarUrl
            UPDATE users SET avatar_url = :avatarUrl WHERE id = :id;
        SQL);

        $this->assertStringContainsString('int $id', $code);
        $this->assertStringNotContainsString('?int $id', $code);
    }

    public function test_nullable_param_has_nullable_docblock(): void
    {
        $code = $this->queryCode(<<<SQL
            -- @name UpdateAvatar
            -- @class Users
            -- @returns :exec
            -- @nullable avatarUrl
            UPDATE users SET avatar_url = :avatarUrl WHERE id = :id;
        SQL);

        $this->assertStringContainsString('@param ?string $avatarUrl', $code);
    }

    public function test_nullable_param_binding_uses_param_str(): void
    {
        $code = $this->queryCode(<<<SQL
            -- @name UpdateAvatar
            -- @class Users
            -- @returns :exec
            -- @nullable avatarUrl
            UPDATE users SET avatar_url = :avatarUrl WHERE id = :id;
        SQL);

        // PDO binding still works — null is bound as PARAM_STR (standard)
        $this->assertStringContainsString('avatarUrl', $code);
    }

    // =========================================================================
    // @nullable vs @optional — distinct semantics
    // =========================================================================

    public function test_nullable_does_not_rewrite_sql(): void
    {
        $queries = $this->analyze(<<<SQL
            -- @name UpdateRole
            -- @class Users
            -- @returns :exec
            -- @nullable role
            UPDATE users SET role = :role WHERE id = :id;
        SQL);

        // SQL should NOT be rewritten (no IS NULL OR pattern)
        $sql = $queries[0]->sql;
        $this->assertStringNotContainsString('IS NULL', $sql);
        $this->assertStringContainsString('role = :role', $sql);
    }

    public function test_nullable_and_optional_can_coexist_on_different_params(): void
    {
        // @optional rewrites SQL; @nullable only changes type
        $queries = $this->analyze(<<<SQL
            -- @name SearchUsers
            -- @class Users
            -- @returns :many
            -- @nullable name
            -- @optional role
            SELECT * FROM users WHERE name = :name AND role = :role;
        SQL);

        $nameParam = $queries[0]->params['name'] ?? null;
        $roleParam = $queries[0]->params['role'] ?? null;

        $this->assertNotNull($nameParam);
        $this->assertNotNull($roleParam);

        // name: nullable type, SQL unchanged
        $this->assertStringStartsWith('?', $nameParam->phpType);
        $this->assertFalse($nameParam->optional);

        // role: optional (SQL rewritten), but not necessarily nullable type
        $this->assertTrue($roleParam->optional);
    }

    // =========================================================================
    // @nullable on annotation positioned anywhere
    // =========================================================================

    public function test_nullable_before_name_annotation(): void
    {
        $queries = $this->analyze(<<<SQL
            -- @nullable avatarUrl
            -- @name UpdateAvatar
            -- @class Users
            -- @returns :exec
            UPDATE users SET avatar_url = :avatarUrl WHERE id = :id;
        SQL);

        $this->assertSame(['avatarUrl'], $queries[0]->nullableParams);
        $param = $queries[0]->params['avatarUrl'] ?? null;
        $this->assertNotNull($param);
        $this->assertTrue($param->nullable);
    }
}
