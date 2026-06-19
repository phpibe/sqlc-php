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
use SqlcPhp\Resolver\ColumnResolver;
use SqlcPhp\Resolver\ExpressionTypeResolver;
use SqlcPhp\Resolver\ParamResolver;
use SqlcPhp\Rewriter\SqlRewriter;
use SqlcPhp\TypeMapper\MySQLTypeMapper;

class PartialTest extends TestCase
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
                country_id INT           NULL,
                score      DECIMAL(8,2)  NULL,
                created_at DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP
            );
            CREATE TABLE posts (
                id      INT AUTO_INCREMENT PRIMARY KEY,
                title   VARCHAR(255) NOT NULL,
                body    TEXT NULL,
                user_id INT NOT NULL,
                status  VARCHAR(20) NOT NULL DEFAULT 'draft'
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
    // Parser — @partial flag
    // =========================================================================

    public function test_partial_flag_parsed(): void
    {
        $q = $this->parser->parse(
            "-- @name PatchUser\n-- @partial\n-- @returns :exec\n" .
            "UPDATE users SET email = COALESCE(:email, email) WHERE id = :id;"
        );
        $this->assertTrue($q[0]->partial);
    }

    public function test_partial_false_by_default(): void
    {
        $q = $this->parser->parse(
            "-- @name UpdateUser\n-- @returns :exec\n" .
            "UPDATE users SET email = :email WHERE id = :id;"
        );
        $this->assertFalse($q[0]->partial);
    }

    public function test_partial_preserved_through_analyzer(): void
    {
        $q = $this->analyze(
            "-- @name PatchUser\n-- @partial\n-- @returns :exec\n" .
            "UPDATE users SET email = COALESCE(:email, email) WHERE id = :id;"
        );
        $this->assertTrue($q[0]->partial);
    }

    // =========================================================================
    // Analyzer validation
    // =========================================================================

    public function test_partial_on_many_throws(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/@partial.*:exec/');
        $this->analyze(
            "-- @name ListUsers\n-- @partial\n-- @returns :many\nSELECT * FROM users;"
        );
    }

    public function test_partial_on_one_throws(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->analyze(
            "-- @name GetUser\n-- @partial\n-- @returns :one\nSELECT * FROM users WHERE id = :id;"
        );
    }

    public function test_partial_on_exec_does_not_throw(): void
    {
        $q = $this->analyze(
            "-- @name PatchUser\n-- @partial\n-- @returns :exec\n" .
            "UPDATE users SET email = COALESCE(:email, email) WHERE id = :id;"
        );
        $this->assertNotEmpty($q);
    }

    // =========================================================================
    // Parameter classification — SET params are optional, WHERE params required
    // =========================================================================

    public function test_coalesce_params_become_optional(): void
    {
        $q = $this->analyze(
            "-- @name PatchUser\n-- @partial\n-- @returns :exec\n" .
            "UPDATE users SET email = COALESCE(:email, email) WHERE id = :id;"
        );

        $params = array_column($q[0]->params, null, 'name');
        $this->assertTrue($params['email']->optional, ':email inside COALESCE must be optional');
        $this->assertFalse($params['id']->optional,   ':id in WHERE must be required');
    }

    public function test_multiple_coalesce_params_all_optional(): void
    {
        $q = $this->analyze(
            "-- @name PatchUser\n-- @partial\n-- @returns :exec\n" .
            "UPDATE users SET\n" .
            "  email  = COALESCE(:email, email),\n" .
            "  name   = COALESCE(:name, name),\n" .
            "  active = COALESCE(:active, active)\n" .
            "WHERE id = :id;"
        );

        $params = array_column($q[0]->params, null, 'name');
        $this->assertTrue($params['email']->optional);
        $this->assertTrue($params['name']->optional);
        $this->assertTrue($params['active']->optional);
        $this->assertFalse($params['id']->optional);
    }

    public function test_optional_params_have_nullable_php_types(): void
    {
        $q = $this->analyze(
            "-- @name PatchUser\n-- @partial\n-- @returns :exec\n" .
            "UPDATE users SET email = COALESCE(:email, email), active = COALESCE(:active, active)\n" .
            "WHERE id = :id;"
        );

        $params = array_column($q[0]->params, null, 'name');
        $this->assertStringStartsWith('?', $params['email']->phpType, 'email must be ?string');
        $this->assertStringStartsWith('?', $params['active']->phpType, 'active must be ?int');
        $this->assertSame('int', $params['id']->phpType, 'id must be non-nullable int');
    }

    // =========================================================================
    // Method signature — correct param ordering
    // =========================================================================

    public function test_required_params_come_before_optional(): void
    {
        $code = $this->code(
            "-- @name PatchUser\n-- @partial\n-- @returns :exec\n" .
            "UPDATE users SET email = COALESCE(:email, email), name = COALESCE(:name, name)\n" .
            "WHERE id = :id;"
        );

        // Extract the method signature specifically
        preg_match('/function patchUser\([^)]+\)/', $code, $m);
        $this->assertNotEmpty($m, 'patchUser method not found');
        $sig = $m[0];

        // $id (required) must appear before $email/$name (optional)
        $idPos    = strpos($sig, '$id');
        $emailPos = strpos($sig, '$email');
        $this->assertNotFalse($idPos);
        $this->assertNotFalse($emailPos);
        $this->assertLessThan($emailPos, $idPos, '$id must come before $email in signature');
    }

    public function test_generated_signature_has_id_first(): void
    {
        $code = $this->code(
            "-- @name PatchUser\n-- @partial\n-- @returns :exec\n" .
            "UPDATE users SET email = COALESCE(:email, email) WHERE id = :id;"
        );
        $this->assertStringContainsString(
            'function patchUser(int $id, ?string $email = null)',
            $code
        );
    }

    public function test_full_patch_signature(): void
    {
        $code = $this->code(
            "-- @name PatchUser\n-- @partial\n-- @returns :exec\n" .
            "UPDATE users SET\n" .
            "  email  = COALESCE(:email, email),\n" .
            "  name   = COALESCE(:name, name),\n" .
            "  active = COALESCE(:active, active)\n" .
            "WHERE id = :id;"
        );

        // Method must accept id first, then optionals
        $this->assertStringContainsString('int $id', $code);
        $this->assertStringContainsString('?string $email = null', $code);
        $this->assertStringContainsString('?string $name = null', $code);
        $this->assertStringContainsString('?int $active = null', $code);
        $this->assertStringContainsString(': void', $code);
    }

    public function test_multiple_required_where_params(): void
    {
        // Both id and country_id are required (in WHERE, not in COALESCE)
        $code = $this->code(
            "-- @name PatchUserInCountry\n-- @partial\n-- @returns :exec\n" .
            "UPDATE users SET name = COALESCE(:name, name)\n" .
            "WHERE id = :id AND country_id = :country_id;"
        );

        // Extract signature
        preg_match('/function patchUserInCountry\([^)]+\)/', $code, $m);
        $this->assertNotEmpty($m, 'patchUserInCountry method not found');
        $sig = $m[0];

        $idPos      = strpos($sig, '$id');
        $countryPos = strpos($sig, '$country_id');
        $namePos    = strpos($sig, '?string $name');

        $this->assertNotFalse($idPos);
        $this->assertNotFalse($countryPos);
        $this->assertNotFalse($namePos);
        $this->assertLessThan($namePos, $idPos,      '$id must be before optional $name');
        $this->assertLessThan($namePos, $countryPos, '$country_id must be before optional $name');
    }

    // =========================================================================
    // Generated method body — correct PDO bindings
    // =========================================================================

    public function test_generated_method_binds_all_params(): void
    {
        $code = $this->code(
            "-- @name PatchUser\n-- @partial\n-- @returns :exec\n" .
            "UPDATE users SET email = COALESCE(:email, email) WHERE id = :id;"
        );

        $this->assertStringContainsString("bindValue(':id'",    $code);
        $this->assertStringContainsString("bindValue(':email'", $code);
    }

    public function test_generated_method_returns_void(): void
    {
        $code = $this->code(
            "-- @name PatchUser\n-- @partial\n-- @returns :exec\n" .
            "UPDATE users SET email = COALESCE(:email, email) WHERE id = :id;"
        );
        $this->assertStringContainsString('): void', $code);
    }

    public function test_generated_method_uses_execute(): void
    {
        $code = $this->code(
            "-- @name PatchUser\n-- @partial\n-- @returns :exec\n" .
            "UPDATE users SET email = COALESCE(:email, email) WHERE id = :id;"
        );
        $this->assertStringContainsString('$stmt->execute()', $code);
    }

    public function test_sql_preserved_in_generated_method(): void
    {
        $code = $this->code(
            "-- @name PatchUser\n-- @partial\n-- @returns :exec\n" .
            "UPDATE users SET email = COALESCE(:email, email) WHERE id = :id;"
        );
        $this->assertStringContainsString('COALESCE(:email, email)', $code);
        $this->assertStringContainsString('WHERE id = :id', $code);
    }

    // =========================================================================
    // @partial does not affect non-partial queries in same class
    // =========================================================================

    public function test_non_partial_exec_unchanged(): void
    {
        $sql = <<<SQL
            -- @name DeleteUser
            -- @returns :exec
            DELETE FROM users WHERE id = :id;

            -- @name PatchUser
            -- @partial
            -- @returns :exec
            UPDATE users SET email = COALESCE(:email, email) WHERE id = :id;
        SQL;

        $q     = $this->analyze($sql);
        $files = $this->qg->generate($q);
        $code  = $files['UserQuery']['code'];

        // deleteUser should have required $id only
        $deleteStart = strpos($code, 'function deleteUser');
        $patchStart  = strpos($code, 'function patchUser');
        $this->assertNotFalse($deleteStart);
        $this->assertNotFalse($patchStart);

        $deleteCode = substr($code, $deleteStart, $patchStart - $deleteStart);
        $this->assertStringNotContainsString('= null', $deleteCode);
        $this->assertStringContainsString('int $id', $deleteCode);
    }

    // =========================================================================
    // @partial + @class
    // =========================================================================

    public function test_partial_with_class_annotation(): void
    {
        $q    = $this->analyze(
            "-- @name PatchUser\n-- @class User\n-- @partial\n-- @returns :exec\n" .
            "UPDATE users SET name = COALESCE(:name, name) WHERE id = :id;"
        );
        $code = $this->qg->generate($q)['UserQuery']['code'];

        $this->assertStringContainsString('function patchUser(int $id, ?string $name = null): void', $code);
    }

    // =========================================================================
    // @partial with nullable column in SET
    // =========================================================================

    public function test_nullable_set_column_stays_nullable(): void
    {
        // name is NULL in schema — should remain ?string
        $q = $this->analyze(
            "-- @name PatchUser\n-- @partial\n-- @returns :exec\n" .
            "UPDATE users SET name = COALESCE(:name, name) WHERE id = :id;"
        );
        $params = array_column($q[0]->params, null, 'name');
        $this->assertSame('?string', $params['name']->phpType);
    }

    // =========================================================================
    // @partial with posts table
    // =========================================================================

    public function test_partial_on_posts_table(): void
    {
        $q    = $this->analyze(
            "-- @name PatchPost\n-- @class Post\n-- @partial\n-- @returns :exec\n" .
            "UPDATE posts SET\n" .
            "  title  = COALESCE(:title, title),\n" .
            "  body   = COALESCE(:body, body),\n" .
            "  status = COALESCE(:status, status)\n" .
            "WHERE id = :id AND user_id = :user_id;"
        );
        $code = $this->qg->generate($q)['PostQuery']['code'];

        // Required first
        $this->assertStringContainsString('int $id', $code);
        $this->assertStringContainsString('int $user_id', $code);
        // Optional after
        $this->assertStringContainsString('?string $title = null', $code);
        $this->assertStringContainsString('?string $body = null', $code);
        $this->assertStringContainsString('?string $status = null', $code);
    }

    // =========================================================================
    // Interface generation — partial method included
    // =========================================================================

    public function test_interface_includes_partial_method(): void
    {
        $dtoGen = new ResultDtoGenerator('App', $this->mapper);
        $ig     = new InterfaceGenerator('App');
        $qg     = new QueryGenerator($this->catalog, $this->mapper, $dtoGen, 'App', true, $ig);

        $q     = $this->analyze(
            "-- @name PatchUser\n-- @partial\n-- @returns :exec\n" .
            "UPDATE users SET email = COALESCE(:email, email) WHERE id = :id;"
        );
        $files = $qg->generateInterfaces($q);
        $iface = $files['UserQueryInterface']['code'];

        $this->assertStringContainsString('patchUser(int $id, ?string $email = null): void', $iface);
    }

    // =========================================================================
    // Version
    // =========================================================================

    public function test_version_is_2_7_1(): void
    {
        $this->assertSame('2.12.0', \SqlcPhp\Version::VERSION);
    }
}
