<?php

declare(strict_types=1);

namespace SqlcPhp\Tests\Generator;

use PHPUnit\Framework\TestCase;
use SqlcPhp\Analyzer\QueryAnalyzer;
use SqlcPhp\Catalog\SchemaCatalog;
use SqlcPhp\Generator\EnumGenerator;
use SqlcPhp\Generator\InterfaceGenerator;
use SqlcPhp\Generator\ModelGenerator;
use SqlcPhp\Generator\QueryGenerator;
use SqlcPhp\Generator\ResultDtoGenerator;
use SqlcPhp\Parser\QueryParser;
use SqlcPhp\Parser\SchemaParser;
use SqlcPhp\Resolver\ColumnResolver;
use SqlcPhp\Resolver\ExpressionTypeResolver;
use SqlcPhp\Resolver\ParamResolver;
use SqlcPhp\Rewriter\SqlRewriter;
use SqlcPhp\TypeMapper\MySQLTypeMapper;

class InterfaceGeneratorTest extends TestCase
{
    private SchemaCatalog      $catalog;
    private MySQLTypeMapper    $mapper;
    private QueryParser        $queryParser;
    private QueryAnalyzer      $analyzer;
    private ResultDtoGenerator $dtoGen;
    private InterfaceGenerator $interfaceGen;

    private const NS = 'App\\Database';

    protected function setUp(): void
    {
        $schema = <<<SQL
            CREATE TABLE users (
                id       INT          AUTO_INCREMENT PRIMARY KEY,
                email    VARCHAR(100) NOT NULL,
                username VARCHAR(45)  null,
                active   TINYINT      DEFAULT 1 null,
                role_id  SMALLINT     NOT NULL
            );
        SQL;

        $this->catalog      = new SchemaCatalog((new SchemaParser())->parse($schema));
        $this->mapper       = new MySQLTypeMapper([], new EnumGenerator(self::NS));
        $this->queryParser  = new QueryParser();
        $paramResolver      = new ParamResolver($this->catalog, $this->mapper);
        $exprResolver       = new ExpressionTypeResolver($this->catalog, $this->mapper);
        $colResolver        = new ColumnResolver($this->catalog, $this->mapper, $paramResolver, $exprResolver);
        $this->analyzer     = new QueryAnalyzer($paramResolver, $colResolver, $this->queryParser, new SqlRewriter());
        $this->dtoGen       = new ResultDtoGenerator(self::NS);
        $this->interfaceGen = new InterfaceGenerator(self::NS);
    }

    private function analyze(string $sql): array
    {
        return $this->analyzer->analyze($this->queryParser->parse($sql));
    }

    private function makeQueryGen(bool $withInterfaces = true): QueryGenerator
    {
        return new QueryGenerator(
            $this->catalog,
            $this->mapper,
            $this->dtoGen,
            self::NS,
            $withInterfaces,
            $withInterfaces ? $this->interfaceGen : null,
        );
    }

    // -------------------------------------------------------------------------
    // InterfaceGenerator — naming
    // -------------------------------------------------------------------------

    public function test_interface_name_appends_interface_suffix(): void
    {
        $this->assertSame('UserQueryInterface', $this->interfaceGen->interfaceName('UserQuery'));
    }

    // -------------------------------------------------------------------------
    // InterfaceGenerator — code generation
    // -------------------------------------------------------------------------

    public function test_generates_interface_keyword(): void
    {
        $queries = $this->analyze(
            "-- @name ListUsers\n-- @returns :many\nSELECT * FROM users;"
        );

        $qg = $this->makeQueryGen();
        ['code' => $code] = $this->interfaceGen->generate('UserQuery', $queries, $qg);

        $this->assertStringContainsString('interface UserQueryInterface', $code);
    }

    public function test_generated_interface_has_correct_namespace(): void
    {
        $queries = $this->analyze("-- @name List\n-- @returns :many\nSELECT * FROM users;");
        $qg      = $this->makeQueryGen();
        ['code' => $code] = $this->interfaceGen->generate('UserQuery', $queries, $qg);

        $this->assertStringContainsString('namespace App\\Database;', $code);
    }

    public function test_interface_has_do_not_edit_notice(): void
    {
        $queries = $this->analyze("-- @name List\n-- @returns :many\nSELECT * FROM users;");
        $qg      = $this->makeQueryGen();
        ['code' => $code] = $this->interfaceGen->generate('UserQuery', $queries, $qg);

        $this->assertStringContainsString('do not edit manually', $code);
    }

    // -------------------------------------------------------------------------
    // Method signatures in interface
    // -------------------------------------------------------------------------

    public function test_interface_contains_many_method_signature(): void
    {
        $queries = $this->analyze(
            "-- @name ListUsers\n-- @returns :many\nSELECT * FROM users;"
        );
        $qg      = $this->makeQueryGen();
        ['code' => $code] = $this->interfaceGen->generate('UserQuery', $queries, $qg);

        $this->assertStringContainsString('public function listUsers(): array;', $code);
    }

    public function test_interface_contains_one_method_non_nullable_return(): void
    {
        $queries = $this->analyze(
            "-- @name GetUser\n-- @returns :one\nSELECT * FROM users WHERE users.id = :id;"
        );
        $qg      = $this->makeQueryGen();
        ['code' => $code] = $this->interfaceGen->generate('UserQuery', $queries, $qg);

        $this->assertStringContainsString('public function getUser(?int $id): User;', $code);
    }

    public function test_interface_contains_opt_method_nullable_return(): void
    {
        $queries = $this->analyze(
            "-- @name FindUser\n-- @returns :opt\nSELECT * FROM users WHERE users.email = :email;"
        );
        $qg      = $this->makeQueryGen();
        ['code' => $code] = $this->interfaceGen->generate('UserQuery', $queries, $qg);

        $this->assertStringContainsString('public function findUser(string $email): ?User;', $code);
    }

    public function test_interface_contains_exec_method_void_return(): void
    {
        $queries = $this->analyze(
            "-- @name DeleteUser\n-- @returns :exec\nDELETE FROM users WHERE id = :id;"
        );
        $qg      = $this->makeQueryGen();
        ['code' => $code] = $this->interfaceGen->generate('UserQuery', $queries, $qg);

        $this->assertStringContainsString('public function deleteUser(?int $id): void;', $code);
    }

    public function test_interface_method_has_param_in_docblock(): void
    {
        $queries = $this->analyze(
            "-- @name GetUser\n-- @returns :one\nSELECT * FROM users WHERE users.id = :id;"
        );
        $qg      = $this->makeQueryGen();
        ['code' => $code] = $this->interfaceGen->generate('UserQuery', $queries, $qg);

        $this->assertStringContainsString('@param', $code);
        $this->assertStringContainsString('$id', $code);
    }

    public function test_interface_optional_param_has_null_note_in_docblock(): void
    {
        $queries = $this->analyze(
            "-- @name Search\n-- @returns :many\n-- @optional active\n" .
            "SELECT * FROM users WHERE active = :active;"
        );
        $qg      = $this->makeQueryGen();
        ['code' => $code] = $this->interfaceGen->generate('UserQuery', $queries, $qg);

        $this->assertStringContainsString('Pass null to skip this filter', $code);
    }

    public function test_interface_optional_param_has_default_in_signature(): void
    {
        $queries = $this->analyze(
            "-- @name Search\n-- @returns :many\n-- @optional active\n" .
            "SELECT * FROM users WHERE active = :active;"
        );
        $qg      = $this->makeQueryGen();
        ['code' => $code] = $this->interfaceGen->generate('UserQuery', $queries, $qg);

        $this->assertStringContainsString('= null', $code);
    }

    public function test_interface_has_multiple_methods(): void
    {
        $sql = <<<SQL
            -- @name ListUsers
            -- @returns :many
            SELECT * FROM users;

            -- @name GetUser
            -- @returns :one
            SELECT * FROM users WHERE users.id = :id;

            -- @name DeleteUser
            -- @returns :exec
            DELETE FROM users WHERE id = :id;
        SQL;

        $queries = $this->analyze($sql);
        $qg      = $this->makeQueryGen();
        ['code' => $code] = $this->interfaceGen->generate('UserQuery', $queries, $qg);

        $this->assertStringContainsString('listUsers', $code);
        $this->assertStringContainsString('getUser', $code);
        $this->assertStringContainsString('deleteUser', $code);
    }

    // -------------------------------------------------------------------------
    // QueryGenerator — implements clause and generateInterfaces()
    // -------------------------------------------------------------------------

    public function test_query_class_implements_interface_when_enabled(): void
    {
        $queries = $this->analyze(
            "-- @name List\n-- @returns :many\nSELECT * FROM users;"
        );
        $qg   = $this->makeQueryGen(withInterfaces: true);
        $code = $qg->generate($queries)['UserQuery']['code'];

        $this->assertStringContainsString('implements UserQueryInterface', $code);
    }

    public function test_query_class_has_no_implements_when_disabled(): void
    {
        $queries = $this->analyze(
            "-- @name List\n-- @returns :many\nSELECT * FROM users;"
        );
        $qg   = $this->makeQueryGen(withInterfaces: false);
        $code = $qg->generate($queries)['UserQuery']['code'];

        $this->assertStringNotContainsString('implements', $code);
    }

    public function test_generate_interfaces_returns_interface_files(): void
    {
        $queries = $this->analyze(
            "-- @name List\n-- @returns :many\nSELECT * FROM users;"
        );
        $qg    = $this->makeQueryGen(withInterfaces: true);
        $files = $qg->generateInterfaces($queries);

        $this->assertArrayHasKey('UserQueryInterface', $files);
    }

    public function test_generate_interfaces_returns_empty_when_disabled(): void
    {
        $queries = $this->analyze(
            "-- @name List\n-- @returns :many\nSELECT * FROM users;"
        );
        $qg    = $this->makeQueryGen(withInterfaces: false);
        $files = $qg->generateInterfaces($queries);

        $this->assertSame([], $files);
    }

    public function test_interface_file_class_name_is_interface_name(): void
    {
        $queries = $this->analyze(
            "-- @name List\n-- @returns :many\nSELECT * FROM users;"
        );
        $qg    = $this->makeQueryGen(withInterfaces: true);
        $files = $qg->generateInterfaces($queries);

        $this->assertSame('UserQueryInterface', $files['UserQueryInterface']['className']);
    }
}
