<?php

declare(strict_types=1);

namespace SqlcPhp\Tests\Generator;

use PHPUnit\Framework\TestCase;
use SqlcPhp\Analyzer\QueryAnalyzer;
use SqlcPhp\Catalog\SchemaCatalog;
use SqlcPhp\Generator\ModelGenerator;
use SqlcPhp\Generator\QueryGenerator;
use SqlcPhp\Generator\ResultDtoGenerator;
use SqlcPhp\Parser\QueryParser;
use SqlcPhp\Parser\SchemaParser;
use SqlcPhp\Resolver\ColumnResolver;
use SqlcPhp\Resolver\ExpressionTypeResolver;
use SqlcPhp\Resolver\ParamResolver;
use SqlcPhp\TypeMapper\MySQLTypeMapper;

class GeneratorTest extends TestCase
{
    private SchemaCatalog    $catalog;
    private MySQLTypeMapper  $mapper;
    private QueryParser      $queryParser;
    private QueryAnalyzer    $analyzer;
    private ModelGenerator   $modelGen;
    private QueryGenerator   $queryGen;
    private ResultDtoGenerator $dtoGen;

    private const NS = 'App\\Database';

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

        $this->catalog     = new SchemaCatalog((new SchemaParser())->parse($schema));
        $this->mapper      = new MySQLTypeMapper();
        $this->queryParser = new QueryParser();
        $paramResolver     = new ParamResolver($this->catalog, $this->mapper);
        $exprResolver      = new ExpressionTypeResolver($this->catalog, $this->mapper);
        $colResolver       = new ColumnResolver($this->catalog, $this->mapper, $paramResolver, $exprResolver);
        $this->analyzer    = new QueryAnalyzer($paramResolver, $colResolver, $this->queryParser);
        $this->dtoGen      = new ResultDtoGenerator(self::NS);
        $this->modelGen    = new ModelGenerator($this->catalog, $this->mapper, $this->queryParser, self::NS);
        $this->queryGen    = new QueryGenerator($this->catalog, $this->mapper, $this->dtoGen, self::NS);
    }

    private function analyzeQuery(string $sql): array
    {
        return $this->analyzer->analyze($this->queryParser->parse($sql));
    }

    // =========================================================================
    // ModelGenerator
    // =========================================================================

    public function test_model_has_correct_namespace(): void
    {
        ['code' => $code] = $this->modelGen->generate('users');
        $this->assertStringContainsString('namespace App\\Database;', $code);
    }

    public function test_model_is_readonly_class(): void
    {
        ['code' => $code] = $this->modelGen->generate('users');
        $this->assertStringContainsString('readonly class User', $code);
    }

    public function test_model_class_name_is_singular_pascal_case(): void
    {
        ['className' => $name] = $this->modelGen->generate('users');
        $this->assertSame('User', $name);
    }

    public function test_model_has_from_row_method(): void
    {
        ['code' => $code] = $this->modelGen->generate('users');
        $this->assertStringContainsString('public static function fromRow(array $row): self', $code);
    }

    public function test_model_properties_have_no_redundant_readonly(): void
    {
        ['code' => $code] = $this->modelGen->generate('users');
        // Properties must NOT have "readonly" keyword (it's on the class)
        $this->assertStringNotContainsString('public readonly', $code);
    }

    public function test_model_contains_all_columns(): void
    {
        ['code' => $code] = $this->modelGen->generate('users');
        $this->assertStringContainsString('$id',         $code);
        $this->assertStringContainsString('$email',      $code);
        $this->assertStringContainsString('$active',     $code);
        $this->assertStringContainsString('$created_at', $code);
    }

    public function test_model_maps_nullable_correctly(): void
    {
        ['code' => $code] = $this->modelGen->generate('users');
        $this->assertStringContainsString('int $id',       $code);  // not nullable — PRIMARY KEY
        $this->assertStringContainsString('string $email',  $code);  // not nullable
    }

    public function test_model_generation_not_ready_message_in_doc(): void
    {
        ['code' => $code] = $this->modelGen->generate('users');
        $this->assertStringContainsString('do not edit manually', $code);
    }

    // =========================================================================
    // QueryGenerator — :many
    // =========================================================================

    public function test_many_method_returns_array(): void
    {
        $queries = $this->analyzeQuery(
            "-- @name ListUsers\n-- @returns :many\nSELECT * FROM users;"
        );
        ['code' => $code] = $this->queryGen->generate($queries)['UserQuery'];

        $this->assertStringContainsString('public function listUsers(): array', $code);
    }

    public function test_many_method_uses_fetch_all(): void
    {
        $queries = $this->analyzeQuery(
            "-- @name ListUsers\n-- @returns :many\nSELECT * FROM users;"
        );
        ['code' => $code] = $this->queryGen->generate($queries)['UserQuery'];

        $this->assertStringContainsString('fetchAll(PDO::FETCH_ASSOC)', $code);
    }

    // =========================================================================
    // QueryGenerator — :one (throws)
    // =========================================================================

    public function test_one_method_return_type_is_non_nullable(): void
    {
        $queries = $this->analyzeQuery(
            "-- @name GetUser\n-- @returns :one\nSELECT * FROM users WHERE users.id = :id;"
        );
        ['code' => $code] = $this->queryGen->generate($queries)['UserQuery'];

        $this->assertStringContainsString('public function getUser(int $id): User', $code);
    }

    public function test_one_method_throws_runtime_exception(): void
    {
        $queries = $this->analyzeQuery(
            "-- @name GetUser\n-- @returns :one\nSELECT * FROM users WHERE users.id = :id;"
        );
        ['code' => $code] = $this->queryGen->generate($queries)['UserQuery'];

        $this->assertStringContainsString('throw new RuntimeException', $code);
        $this->assertStringContainsString('getUser',                    $code);
    }

    public function test_one_method_imports_runtime_exception(): void
    {
        $queries = $this->analyzeQuery(
            "-- @name GetUser\n-- @returns :one\nSELECT * FROM users WHERE users.id = :id;"
        );
        ['code' => $code] = $this->queryGen->generate($queries)['UserQuery'];

        $this->assertStringContainsString('use RuntimeException;', $code);
    }

    // =========================================================================
    // QueryGenerator — :opt (nullable)
    // =========================================================================

    public function test_opt_method_return_type_is_nullable(): void
    {
        $queries = $this->analyzeQuery(
            "-- @name FindUser\n-- @returns :opt\nSELECT * FROM users WHERE users.email = :email;"
        );
        ['code' => $code] = $this->queryGen->generate($queries)['UserQuery'];

        $this->assertStringContainsString('public function findUser(string $email): ?User', $code);
    }

    public function test_opt_method_does_not_throw(): void
    {
        $queries = $this->analyzeQuery(
            "-- @name FindUser\n-- @returns :opt\nSELECT * FROM users WHERE users.email = :email;"
        );
        ['code' => $code] = $this->queryGen->generate($queries)['UserQuery'];

        $this->assertStringNotContainsString('throw new', $code);
    }

    public function test_opt_method_returns_null_on_miss(): void
    {
        $queries = $this->analyzeQuery(
            "-- @name FindUser\n-- @returns :opt\nSELECT * FROM users WHERE users.email = :email;"
        );
        ['code' => $code] = $this->queryGen->generate($queries)['UserQuery'];

        $this->assertStringContainsString('return $row !== false', $code);
    }

    // =========================================================================
    // QueryGenerator — :exec
    // =========================================================================

    public function test_exec_method_returns_void(): void
    {
        $queries = $this->analyzeQuery(
            "-- @name DeleteUser\n-- @returns :exec\nDELETE FROM users WHERE id = :id;"
        );
        ['code' => $code] = $this->queryGen->generate($queries)['UserQuery'];

        $this->assertStringContainsString('public function deleteUser(int $id): void', $code);
    }

    // =========================================================================
    // QueryGenerator — PDO bindings
    // =========================================================================

    public function test_int_param_uses_pdo_param_int(): void
    {
        $queries = $this->analyzeQuery(
            "-- @name Get\n-- @returns :one\nSELECT * FROM users WHERE users.id = :id;"
        );
        ['code' => $code] = $this->queryGen->generate($queries)['UserQuery'];

        $this->assertStringContainsString("bindValue(':id', \$id, PDO::PARAM_INT)", $code);
    }

    public function test_string_param_uses_pdo_param_str(): void
    {
        $queries = $this->analyzeQuery(
            "-- @name Find\n-- @returns :opt\nSELECT * FROM users WHERE users.email = :email;"
        );
        ['code' => $code] = $this->queryGen->generate($queries)['UserQuery'];

        $this->assertStringContainsString("bindValue(':email', \$email, PDO::PARAM_STR)", $code);
    }

    // =========================================================================
    // QueryGenerator — docblock indentation
    // =========================================================================

    public function test_docblock_is_correctly_indented(): void
    {
        $queries = $this->analyzeQuery(
            "-- @name Get\n-- @returns :one\nSELECT * FROM users WHERE users.id = :id;"
        );
        ['code' => $code] = $this->queryGen->generate($queries)['UserQuery'];

        // Only check method-level docblock lines (indented with spaces, not the class-level one)
        preg_match_all('/^([ ]{2,})(\/\*\*|\*[^\/]|\*\/).*$/m', $code, $m);
        $this->assertNotEmpty($m[1], 'No indented docblock lines found');
        foreach ($m[1] as $indent) {
            // Method docblocks use 4 spaces (/**) or 5 spaces (* lines)
            $this->assertContains(strlen($indent), [4, 5],
                "Unexpected docblock indentation of " . strlen($indent) . " spaces"
            );
        }
    }

    // =========================================================================
    // ResultDtoGenerator
    // =========================================================================

    public function test_dto_class_name_is_query_name_plus_row(): void
    {
        $queries = $this->analyzeQuery(<<<SQL
            -- @name GetUserWithRole
            -- @returns :one
            SELECT users.id, roles.name AS role_name
            FROM users
            INNER JOIN roles ON roles.id = users.role_id
            WHERE users.id = :id;
        SQL);

        $name = $this->dtoGen->dtoClassName($queries[0]);
        $this->assertSame('GetUserWithRoleRow', $name);
    }

    public function test_dto_is_readonly_class(): void
    {
        $queries = $this->analyzeQuery(<<<SQL
            -- @name GetJoined
            -- @returns :one
            SELECT users.id, roles.name AS role_name
            FROM users INNER JOIN roles ON roles.id = users.role_id
            WHERE users.id = :id;
        SQL);

        ['code' => $code] = $this->dtoGen->generate($queries[0]);
        $this->assertStringContainsString('readonly class GetJoinedRow', $code);
    }

    public function test_dto_has_from_row_method(): void
    {
        $queries = $this->analyzeQuery(<<<SQL
            -- @name GetJoined
            -- @returns :one
            SELECT users.id, roles.name AS role_name
            FROM users INNER JOIN roles ON roles.id = users.role_id
            WHERE users.id = :id;
        SQL);

        ['code' => $code] = $this->dtoGen->generate($queries[0]);
        $this->assertStringContainsString('public static function fromRow', $code);
    }

    public function test_dto_contains_aliased_property(): void
    {
        $queries = $this->analyzeQuery(<<<SQL
            -- @name GetJoined
            -- @returns :one
            SELECT users.id, roles.name AS role_name
            FROM users INNER JOIN roles ON roles.id = users.role_id
            WHERE users.id = :id;
        SQL);

        ['code' => $code] = $this->dtoGen->generate($queries[0]);
        $this->assertStringContainsString('$role_name', $code);
    }
}
