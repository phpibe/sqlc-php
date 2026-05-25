<?php

declare(strict_types=1);

namespace SqlcPhp\Tests\Analyzer;

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

class OptionalParamTest extends TestCase
{
    private QueryParser   $queryParser;
    private QueryAnalyzer $analyzer;
    private QueryGenerator $queryGen;

    protected function setUp(): void
    {
        $schema = <<<SQL
            CREATE TABLE users (
                id       INT           AUTO_INCREMENT PRIMARY KEY,
                email    VARCHAR(100)  NOT NULL,
                username VARCHAR(45)   null,
                active   TINYINT       DEFAULT 1 null,
                role_id  SMALLINT      NOT NULL,
                status   VARCHAR(50)   null
            );
        SQL;

        $catalog          = new SchemaCatalog((new SchemaParser())->parse($schema));
        $mapper           = new MySQLTypeMapper();
        $this->queryParser = new QueryParser();
        $paramResolver    = new ParamResolver($catalog, $mapper);
        $exprResolver     = new ExpressionTypeResolver($catalog, $mapper);
        $colResolver      = new ColumnResolver($catalog, $mapper, $paramResolver, $exprResolver);

        $this->analyzer = new QueryAnalyzer(
            $paramResolver, $colResolver, $this->queryParser, new SqlRewriter()
        );

        $this->queryGen = new QueryGenerator(
            $catalog, $mapper, new ResultDtoGenerator('App\\Database'), 'App\\Database'
        );
    }

    private function analyze(string $sql): array
    {
        return $this->analyzer->analyze($this->queryParser->parse($sql));
    }

    private function generate(string $sql): string
    {
        $queries = $this->analyze($sql);
        $files   = $this->queryGen->generate($queries);
        return array_values($files)[0]['code'];
    }

    // -------------------------------------------------------------------------
    // QueryParser — @optional annotation
    // -------------------------------------------------------------------------

    public function test_parser_captures_single_optional_param(): void
    {
        $sql = "-- @name List\n-- @returns :many\n-- @optional status\nSELECT * FROM users WHERE status = :status;";
        $queries = $this->queryParser->parse($sql);

        $this->assertContains('status', $queries[0]->optionalParams);
    }

    public function test_parser_captures_multiple_optional_params(): void
    {
        $sql = "-- @name List\n-- @returns :many\n-- @optional status\n-- @optional active\n" .
               "SELECT * FROM users WHERE status = :status AND active = :active;";
        $queries = $this->queryParser->parse($sql);

        $this->assertContains('status', $queries[0]->optionalParams);
        $this->assertContains('active', $queries[0]->optionalParams);
    }

    public function test_parser_throws_on_unknown_optional_param(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches("/@optional 'typo'/");

        $sql = "-- @name List\n-- @returns :many\n-- @optional typo\nSELECT * FROM users WHERE status = :status;";
        $this->queryParser->parse($sql);
    }

    public function test_parser_error_message_lists_known_params(): void
    {
        try {
            $sql = "-- @name List\n-- @returns :many\n-- @optional typo\nSELECT * FROM users WHERE id = :id;";
            $this->queryParser->parse($sql);
            $this->fail('Expected RuntimeException');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('id', $e->getMessage());
        }
    }

    public function test_no_optional_params_leaves_optionalParams_empty(): void
    {
        $sql = "-- @name Get\n-- @returns :one\nSELECT * FROM users WHERE id = :id;";
        $queries = $this->queryParser->parse($sql);

        $this->assertSame([], $queries[0]->optionalParams);
    }

    // -------------------------------------------------------------------------
    // QueryAnalyzer — param marked as optional
    // -------------------------------------------------------------------------

    public function test_analyzer_marks_optional_param(): void
    {
        $queries = $this->analyze(
            "-- @name List\n-- @returns :many\n-- @optional status\n" .
            "SELECT * FROM users WHERE status = :status;"
        );

        $this->assertTrue($queries[0]->params['status']->optional);
    }

    public function test_analyzer_does_not_mark_non_optional_param(): void
    {
        $queries = $this->analyze(
            "-- @name Get\n-- @returns :one\nSELECT * FROM users WHERE id = :id;"
        );

        $this->assertFalse($queries[0]->params['id']->optional);
    }

    public function test_analyzer_rewrites_sql_for_optional_param(): void
    {
        $queries = $this->analyze(
            "-- @name List\n-- @returns :many\n-- @optional status\n" .
            "SELECT * FROM users WHERE status = :status;"
        );

        $this->assertStringContainsString(':status IS NULL OR', $queries[0]->sql);
    }

    public function test_analyzer_does_not_rewrite_non_optional_param(): void
    {
        $queries = $this->analyze(
            "-- @name Get\n-- @returns :one\nSELECT * FROM users WHERE id = :id;"
        );

        $this->assertStringNotContainsString('IS NULL', $queries[0]->sql);
    }

    public function test_analyzer_marks_only_declared_params_as_optional(): void
    {
        $queries = $this->analyze(
            "-- @name List\n-- @returns :many\n-- @optional status\n" .
            "SELECT * FROM users WHERE id = :id AND status = :status;"
        );

        $this->assertFalse($queries[0]->params['id']->optional);
        $this->assertTrue($queries[0]->params['status']->optional);
    }

    // -------------------------------------------------------------------------
    // QueryGenerator — method signature and SQL
    // -------------------------------------------------------------------------

    public function test_optional_param_has_null_default_in_signature(): void
    {
        $code = $this->generate(
            "-- @name ListByStatus\n-- @returns :many\n-- @optional status\n" .
            "SELECT * FROM users WHERE status = :status;"
        );

        $this->assertStringContainsString('?string $status = null', $code);
    }

    public function test_required_param_has_no_default_in_signature(): void
    {
        $code = $this->generate(
            "-- @name Get\n-- @returns :one\nSELECT * FROM users WHERE id = :id;"
        );

        $this->assertStringNotContainsString('= null', $code);
    }

    public function test_required_params_appear_before_optional_in_signature(): void
    {
        $code = $this->generate(
            "-- @name Search\n-- @returns :many\n-- @optional status\n" .
            "SELECT * FROM users WHERE id = :id AND status = :status;"
        );

        // id (required) must come before status (optional) in the signature
        $idPos     = strpos($code, '$id');
        $statusPos = strpos($code, '$status');
        $this->assertLessThan($statusPos, $idPos);
    }

    public function test_optional_param_type_is_nullable(): void
    {
        $code = $this->generate(
            "-- @name List\n-- @returns :many\n-- @optional active\n" .
            "SELECT * FROM users WHERE active = :active;"
        );

        // Type must have ? prefix
        $this->assertMatchesRegularExpression('/\?[a-zA-Z]+\s+\$active\s*=\s*null/', $code);
    }

    public function test_generated_sql_contains_is_null_rewrite(): void
    {
        $code = $this->generate(
            "-- @name List\n-- @returns :many\n-- @optional status\n" .
            "SELECT * FROM users WHERE status = :status;"
        );

        $this->assertStringContainsString(':status IS NULL OR', $code);
    }

    public function test_docblock_notes_optional_param(): void
    {
        $code = $this->generate(
            "-- @name List\n-- @returns :many\n-- @optional status\n" .
            "SELECT * FROM users WHERE status = :status;"
        );

        $this->assertStringContainsString('Pass null to skip this filter', $code);
    }

    public function test_analyzer_throws_when_optional_used_with_join(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/JOIN/');

        $this->analyze(<<<SQL
            -- @name SearchWithRole
            -- @returns :many
            -- @optional status
            SELECT users.* FROM users
            INNER JOIN roles ON roles.id = users.role_id
            WHERE users.status = :status;
        SQL);
    }

    public function test_analyzer_throws_when_optional_used_with_having(): void
    {
        $this->expectException(\RuntimeException::class);
        // HAVING queries have no WHERE — caught by assertOptionalInWhereContext
        $this->expectExceptionMessageMatches('/cannot be used on a query without a WHERE clause|HAVING/');

        $this->analyze(<<<SQL
            -- @name CountByRole
            -- @returns :many
            -- @optional minCount
            SELECT role_id, COUNT(*) as total FROM users
            GROUP BY role_id
            HAVING COUNT(*) > :minCount;
        SQL);
    }

    public function test_analyzer_throws_when_optional_used_with_subquery(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/subquer/i');

        $this->analyze(<<<SQL
            -- @name FindWithOrders
            -- @returns :many
            -- @optional status
            SELECT * FROM users
            WHERE id IN (SELECT user_id FROM orders WHERE status = :status);
        SQL);
    }

    public function test_analyzer_error_message_contains_query_name(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches("/Query 'searchWithRole'/");

        $this->analyze(<<<SQL
            -- @name SearchWithRole
            -- @returns :many
            -- @optional status
            SELECT users.* FROM users
            INNER JOIN roles ON roles.id = users.role_id
            WHERE users.status = :status;
        SQL);
    }

    public function test_both_params_optional_all_have_defaults(): void
    {
        $code = $this->generate(
            "-- @name Search\n-- @returns :many\n-- @optional email\n-- @optional username\n" .
            "SELECT * FROM users WHERE email = :email AND username = :username;"
        );

        $this->assertStringContainsString('$email = null', $code);
        $this->assertStringContainsString('$username = null', $code);
    }
}
