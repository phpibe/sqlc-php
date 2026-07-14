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
 * Tests for the unified @param syntax (v2.17.0).
 *
 * @param now handles three roles in one annotation:
 *
 *   @param name table.col          → type hint from schema (legacy, unchanged)
 *   @param name phpType            → explicit PHP type (e.g. string, int, float)
 *   @param name ?phpType           → nullable PHP type  (replaces @nullable)
 *   @param name phpType:optional   → optional WHERE param, SQL rewritten (replaces @optional)
 *   @param name ?phpType:optional  → nullable + optional
 *
 * @optional and @nullable are deprecated — they still work but emit a
 * stderr deprecation warning.
 */
class UnifiedParamAnnotationTest extends TestCase
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
                id         INT           AUTO_INCREMENT PRIMARY KEY,
                email      VARCHAR(100)  NOT NULL,
                name       VARCHAR(100)  NOT NULL,
                avatar_url VARCHAR(255)  NULL,
                role       VARCHAR(20)   NOT NULL DEFAULT 'client',
                deleted_at DATETIME      NULL,
                active     TINYINT       NOT NULL DEFAULT 1,
                score      DECIMAL(5,2)  NULL
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
    // Parser — @param phpType (explicit non-nullable)
    // =========================================================================

    public function test_param_explicit_int_type(): void
    {
        $queries = $this->analyze(<<<SQL
            -- @name GetUser
            -- @class Users
            -- @returns :opt
            -- @param id int
            SELECT * FROM users WHERE id = :id;
        SQL);

        $p = $queries[0]->params['id'] ?? null;
        $this->assertNotNull($p);
        $this->assertSame('int', $p->phpType);
        $this->assertFalse($p->nullable);
        $this->assertFalse($p->optional);
    }

    public function test_param_explicit_string_type(): void
    {
        $queries = $this->analyze(<<<SQL
            -- @name GetByEmail
            -- @class Users
            -- @returns :opt
            -- @param email string
            SELECT * FROM users WHERE email = :email;
        SQL);

        $p = $queries[0]->params['email'];
        $this->assertSame('string', $p->phpType);
        $this->assertFalse($p->nullable);
    }

    public function test_param_explicit_float_type(): void
    {
        $queries = $this->analyze(<<<SQL
            -- @name GetByScore
            -- @class Users
            -- @returns :opt
            -- @param score float
            SELECT * FROM users WHERE score = :score;
        SQL);

        $p = $queries[0]->params['score'];
        $this->assertSame('float', $p->phpType);
    }

    // =========================================================================
    // Parser — @param ?phpType (nullable, replaces @nullable)
    // =========================================================================

    public function test_param_nullable_string(): void
    {
        $queries = $this->analyze(<<<SQL
            -- @name UpdateAvatar
            -- @class Users
            -- @returns :exec
            -- @param avatarUrl ?string
            UPDATE users SET avatar_url = :avatarUrl WHERE id = :id;
        SQL);

        $p = $queries[0]->params['avatarUrl'] ?? null;
        $this->assertNotNull($p);
        $this->assertSame('?string', $p->phpType);
        $this->assertTrue($p->nullable);
        $this->assertFalse($p->optional);
    }

    public function test_param_nullable_int(): void
    {
        $queries = $this->analyze(<<<SQL
            -- @name UpdateScore
            -- @class Users
            -- @returns :exec
            -- @param score ?int
            UPDATE users SET score = :score WHERE id = :id;
        SQL);

        $p = $queries[0]->params['score'];
        $this->assertSame('?int', $p->phpType);
        $this->assertTrue($p->nullable);
        $this->assertFalse($p->optional);
    }

    public function test_param_nullable_does_not_rewrite_sql(): void
    {
        $queries = $this->analyze(<<<SQL
            -- @name UpdateAvatar
            -- @class Users
            -- @returns :exec
            -- @param avatarUrl ?string
            UPDATE users SET avatar_url = :avatarUrl WHERE id = :id;
        SQL);

        // SQL must NOT be rewritten — ?string only changes the PHP type
        $this->assertStringNotContainsString('IS NULL', $queries[0]->sql);
        $this->assertStringContainsString('avatar_url = :avatarUrl', $queries[0]->sql);
    }

    public function test_multiple_nullable_params_on_separate_lines(): void
    {
        $queries = $this->analyze(<<<SQL
            -- @name UpdateProfile
            -- @class Users
            -- @returns :exec
            -- @param avatarUrl ?string
            -- @param deletedAt ?string
            UPDATE users SET avatar_url = :avatarUrl, deleted_at = :deletedAt WHERE id = :id;
        SQL);

        $this->assertSame('?string', $queries[0]->params['avatarUrl']->phpType);
        $this->assertSame('?string', $queries[0]->params['deletedAt']->phpType);
        $this->assertSame('int',     $queries[0]->params['id']->phpType);
    }

    // =========================================================================
    // Parser — @param phpType:optional (replaces @optional)
    // =========================================================================

    public function test_param_optional_with_explicit_type(): void
    {
        $queries = $this->analyze(<<<SQL
            -- @name ListUsers
            -- @class Users
            -- @returns :many
            -- @param role ?string:optional
            SELECT * FROM users WHERE role = :role;
        SQL);

        $p = $queries[0]->params['role'] ?? null;
        $this->assertNotNull($p);
        $this->assertSame('?string', $p->phpType);
        $this->assertTrue($p->optional);
        $this->assertTrue($p->nullable);
    }

    public function test_param_optional_rewrites_sql(): void
    {
        $queries = $this->analyze(<<<SQL
            -- @name ListUsers
            -- @class Users
            -- @returns :many
            -- @param role ?string:optional
            SELECT * FROM users WHERE role = :role;
        SQL);

        // SQL must be rewritten for optional params
        $this->assertStringContainsString('IS NULL', $queries[0]->sql);
    }

    public function test_param_optional_int(): void
    {
        $queries = $this->analyze(<<<SQL
            -- @name ListUsers
            -- @class Users
            -- @returns :many
            -- @param active ?int:optional
            SELECT * FROM users WHERE active = :active;
        SQL);

        $p = $queries[0]->params['active'];
        $this->assertSame('?int', $p->phpType);
        $this->assertTrue($p->optional);
    }

    public function test_param_optional_without_question_mark_still_becomes_nullable(): void
    {
        // :optional implies nullable even without ? prefix
        $queries = $this->analyze(<<<SQL
            -- @name ListUsers
            -- @class Users
            -- @returns :many
            -- @param role string:optional
            SELECT * FROM users WHERE role = :role;
        SQL);

        $p = $queries[0]->params['role'];
        $this->assertSame('?string', $p->phpType);
        $this->assertTrue($p->optional);
        $this->assertTrue($p->nullable);
    }

    public function test_multiple_optional_params(): void
    {
        $queries = $this->analyze(<<<SQL
            -- @name ListUsers
            -- @class Users
            -- @returns :many
            -- @param role   ?string:optional
            -- @param active ?int:optional
            SELECT * FROM users WHERE role = :role AND active = :active;
        SQL);

        $this->assertTrue($queries[0]->params['role']->optional);
        $this->assertTrue($queries[0]->params['active']->optional);
    }

    // =========================================================================
    // Legacy — table.col type hint (unchanged)
    // =========================================================================

    public function test_param_table_col_legacy_still_works(): void
    {
        $queries = $this->analyze(<<<SQL
            -- @name GetUser
            -- @class Users
            -- @returns :opt
            -- @param id users.id
            SELECT * FROM users WHERE id = :id;
        SQL);

        $p = $queries[0]->params['id'];
        $this->assertSame('int', $p->phpType);
        $this->assertFalse($p->nullable);
    }

    public function test_param_table_col_distinguished_by_dot(): void
    {
        // Ensure "users.id" is not mistaken for a PHP type
        $queries = $this->analyze(<<<SQL
            -- @name GetUser
            -- @class Users
            -- @returns :opt
            -- @param userId users.id
            SELECT * FROM users WHERE id = :userId;
        SQL);

        // Should resolve type from schema (int), not treat "users.id" as PHP type
        $p = $queries[0]->params['userId'];
        $this->assertSame('int', $p->phpType);
    }

    // =========================================================================
    // Generated signatures
    // =========================================================================

    public function test_nullable_param_generates_nullable_signature(): void
    {
        $code = $this->queryCode(<<<SQL
            -- @name UpdateAvatar
            -- @class Users
            -- @returns :exec
            -- @param avatarUrl ?string
            UPDATE users SET avatar_url = :avatarUrl WHERE id = :id;
        SQL);

        $this->assertStringContainsString('?string $avatarUrl', $code);
        $this->assertStringContainsString('int $id', $code);
    }

    public function test_optional_param_generates_nullable_with_default_null(): void
    {
        $code = $this->queryCode(<<<SQL
            -- @name ListUsers
            -- @class Users
            -- @returns :many
            -- @param role ?string:optional
            SELECT * FROM users WHERE role = :role;
        SQL);

        $this->assertStringContainsString('?string $role = null', $code);
    }

    public function test_nullable_param_generates_docblock_with_nullable_type(): void
    {
        $code = $this->queryCode(<<<SQL
            -- @name UpdateAvatar
            -- @class Users
            -- @returns :exec
            -- @param avatarUrl ?string
            UPDATE users SET avatar_url = :avatarUrl WHERE id = :id;
        SQL);

        $this->assertStringContainsString('@param ?string $avatarUrl', $code);
    }

    public function test_all_param_modifiers_together(): void
    {
        $code = $this->queryCode(<<<SQL
            -- @name UpdateAndFilter
            -- @class Users
            -- @returns :exec
            -- @param avatarUrl ?string
            -- @param role      ?string:optional
            -- @param id        int
            UPDATE users SET avatar_url = :avatarUrl WHERE id = :id AND (:role_chk IS NULL OR role = :role);
        SQL);

        $this->assertStringContainsString('?string $avatarUrl', $code);
        $this->assertStringContainsString('int $id', $code);
        $this->assertStringContainsString('?string $role', $code);
    }

    // =========================================================================
    // Coexistence with @comment, @type, @cursor etc.
    // =========================================================================

    public function test_param_coexists_with_comment_annotation(): void
    {
        $queries = $this->analyze(<<<SQL
            -- @name UpdateAvatar
            -- @class Users
            -- @returns :exec
            -- @comment Set the user avatar. Pass null to clear it.
            -- @param avatarUrl ?string
            UPDATE users SET avatar_url = :avatarUrl WHERE id = :id;
        SQL);

        $this->assertSame(['Set the user avatar. Pass null to clear it.'], $queries[0]->comment);
        $this->assertSame('?string', $queries[0]->params['avatarUrl']->phpType);
    }

    // =========================================================================
    // Deprecation warnings
    // =========================================================================

    public function test_old_optional_annotation_still_works(): void
    {
        // @optional is deprecated but must still function correctly
        $queries = $this->analyze(<<<SQL
            -- @name ListUsers
            -- @class Users
            -- @returns :many
            -- @optional role
            SELECT * FROM users WHERE role = :role;
        SQL);

        $p = $queries[0]->params['role'];
        $this->assertTrue($p->optional);
        $this->assertTrue($p->nullable);
    }

    public function test_old_nullable_annotation_still_works(): void
    {
        // @nullable is deprecated but must still function correctly
        $queries = $this->analyze(<<<SQL
            -- @name UpdateAvatar
            -- @class Users
            -- @returns :exec
            -- @nullable avatarUrl
            UPDATE users SET avatar_url = :avatarUrl WHERE id = :id;
        SQL);

        $p = $queries[0]->params['avatarUrl'];
        $this->assertTrue($p->nullable);
        $this->assertFalse($p->optional);
    }
}
