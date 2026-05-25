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

class InListParamTest extends TestCase
{
    private SchemaCatalog   $catalog;
    private MySQLTypeMapper $mapper;
    private QueryParser     $parser;
    private QueryAnalyzer   $analyzer;
    private QueryGenerator  $queryGen;

    protected function setUp(): void
    {
        $schema = <<<SQL
            CREATE TABLE users (
                id      INT          AUTO_INCREMENT PRIMARY KEY,
                email   VARCHAR(100) NOT NULL,
                role_id SMALLINT     NOT NULL,
                active  TINYINT      NOT NULL DEFAULT 1
            );
            CREATE TABLE roles (
                id   SMALLINT     AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(100) NOT NULL
            );
        SQL;

        $this->catalog  = new SchemaCatalog((new SchemaParser())->parse($schema));
        $this->mapper   = new MySQLTypeMapper([], new EnumGenerator('App'));
        $this->parser   = new QueryParser();
        $pr             = new ParamResolver($this->catalog, $this->mapper);
        $er             = new ExpressionTypeResolver($this->catalog, $this->mapper);
        $cr             = new ColumnResolver($this->catalog, $this->mapper, $pr, $er);
        $this->analyzer = new QueryAnalyzer($pr, $cr, $this->parser, new SqlRewriter());
        $dg             = new ResultDtoGenerator('App');
        $this->queryGen = new QueryGenerator($this->catalog, $this->mapper, $dg, 'App');
    }

    private function analyze(string $sql): array
    {
        return $this->analyzer->analyze($this->parser->parse($sql));
    }

    private function generateCode(string $sql): string
    {
        return $this->queryGen->generate($this->analyze($sql))['UserQuery']['code'];
    }

    // =========================================================================
    // ParamResolver — detection
    // =========================================================================

    public function test_in_param_is_detected_as_in_list(): void
    {
        $q = $this->analyze("-- @name GetByIds\n-- @returns :many\nSELECT * FROM users WHERE id IN (:ids);");

        $this->assertTrue($q[0]->params['ids']->inList);
    }

    public function test_regular_param_is_not_in_list(): void
    {
        $q = $this->analyze("-- @name Get\n-- @returns :one\nSELECT * FROM users WHERE id = :id;");

        $this->assertFalse($q[0]->params['id']->inList);
    }

    public function test_in_param_type_is_inferred_from_column(): void
    {
        $q = $this->analyze("-- @name GetByIds\n-- @returns :many\nSELECT * FROM users WHERE id IN (:ids);");

        // id is INT → int
        $this->assertSame('int', $q[0]->params['ids']->phpType);
        $this->assertSame('PDO::PARAM_INT', $q[0]->params['ids']->pdoParam);
    }

    public function test_in_param_type_is_inferred_as_string_for_varchar_column(): void
    {
        $q = $this->analyze("-- @name GetByEmails\n-- @returns :many\nSELECT * FROM users WHERE email IN (:emails);");

        $this->assertSame('string', $q[0]->params['emails']->phpType);
    }

    public function test_not_in_param_is_also_detected(): void
    {
        $q = $this->analyze("-- @name ExcludeIds\n-- @returns :many\nSELECT * FROM users WHERE id NOT IN (:excludedIds);");

        $this->assertTrue($q[0]->params['excludedIds']->inList);
        $this->assertSame('int', $q[0]->params['excludedIds']->phpType);
    }

    public function test_multiple_in_params_all_detected(): void
    {
        $q = $this->analyze(
            "-- @name Filter\n-- @returns :many\n" .
            "SELECT * FROM users WHERE id IN (:ids) AND role_id IN (:roleIds);"
        );

        $this->assertTrue($q[0]->params['ids']->inList);
        $this->assertTrue($q[0]->params['roleIds']->inList);
    }

    public function test_mixed_in_and_regular_params(): void
    {
        $q = $this->analyze(
            "-- @name Filter\n-- @returns :many\n" .
            "SELECT * FROM users WHERE id IN (:ids) AND active = :active;"
        );

        $this->assertTrue($q[0]->params['ids']->inList);
        $this->assertFalse($q[0]->params['active']->inList);
    }

    public function test_qualified_column_in_in_clause(): void
    {
        $q = $this->analyze(
            "-- @name GetByIds\n-- @returns :many\n" .
            "SELECT * FROM users WHERE users.id IN (:ids);"
        );

        $this->assertTrue($q[0]->params['ids']->inList);
        $this->assertSame('int', $q[0]->params['ids']->phpType);
    }

    // =========================================================================
    // QueryGenerator — signature
    // =========================================================================

    public function test_in_param_generates_array_type_in_signature(): void
    {
        $code = $this->generateCode(
            "-- @name GetByIds\n-- @returns :many\nSELECT * FROM users WHERE id IN (:ids);"
        );

        $this->assertStringContainsString('array $ids', $code);
    }

    public function test_in_param_does_not_generate_scalar_type_in_signature(): void
    {
        $code = $this->generateCode(
            "-- @name GetByIds\n-- @returns :many\nSELECT * FROM users WHERE id IN (:ids);"
        );

        $this->assertStringNotContainsString('int $ids', $code);
    }

    public function test_docblock_shows_array_type_with_element_type(): void
    {
        $code = $this->generateCode(
            "-- @name GetByIds\n-- @returns :many\nSELECT * FROM users WHERE id IN (:ids);"
        );

        $this->assertStringContainsString('int[] $ids', $code);
    }

    public function test_docblock_mentions_non_empty_requirement(): void
    {
        $code = $this->generateCode(
            "-- @name GetByIds\n-- @returns :many\nSELECT * FROM users WHERE id IN (:ids);"
        );

        $this->assertStringContainsString('must be non-empty', $code);
    }

    // =========================================================================
    // QueryGenerator — IN() expansion code
    // =========================================================================

    public function test_generated_method_contains_runtime_expansion_comment(): void
    {
        $code = $this->generateCode(
            "-- @name GetByIds\n-- @returns :many\nSELECT * FROM users WHERE id IN (:ids);"
        );

        $this->assertStringContainsString('Expand IN() placeholders dynamically', $code);
    }

    public function test_generated_method_uses_sql_variable(): void
    {
        $code = $this->generateCode(
            "-- @name GetByIds\n-- @returns :many\nSELECT * FROM users WHERE id IN (:ids);"
        );

        $this->assertStringContainsString('$__sql =', $code);
    }

    public function test_generated_method_uses_placeholder_variable(): void
    {
        $code = $this->generateCode(
            "-- @name GetByIds\n-- @returns :many\nSELECT * FROM users WHERE id IN (:ids);"
        );

        $this->assertStringContainsString('$__ph_ids', $code);
    }

    public function test_generated_method_uses_array_fill_for_placeholders(): void
    {
        $code = $this->generateCode(
            "-- @name GetByIds\n-- @returns :many\nSELECT * FROM users WHERE id IN (:ids);"
        );

        $this->assertStringContainsString('array_fill(0, count($ids), \'?\')', $code);
    }

    public function test_generated_method_uses_str_replace_for_substitution(): void
    {
        $code = $this->generateCode(
            "-- @name GetByIds\n-- @returns :many\nSELECT * FROM users WHERE id IN (:ids);"
        );

        $this->assertStringContainsString("str_replace(':ids'", $code);
    }

    public function test_generated_method_prepares_from_runtime_sql_variable(): void
    {
        $code = $this->generateCode(
            "-- @name GetByIds\n-- @returns :many\nSELECT * FROM users WHERE id IN (:ids);"
        );

        $this->assertStringContainsString('pdo->prepare($__sql)', $code);
    }

    public function test_generated_method_uses_spread_in_execute(): void
    {
        $code = $this->generateCode(
            "-- @name GetByIds\n-- @returns :many\nSELECT * FROM users WHERE id IN (:ids);"
        );

        $this->assertStringContainsString('execute([...$ids])', $code);
    }

    public function test_generated_method_has_empty_guard(): void
    {
        $code = $this->generateCode(
            "-- @name GetByIds\n-- @returns :many\nSELECT * FROM users WHERE id IN (:ids);"
        );

        $this->assertStringContainsString('empty($ids)', $code);
        $this->assertStringContainsString('InvalidArgumentException', $code);
    }

    // =========================================================================
    // Mixed IN + regular params
    // =========================================================================

    public function test_mixed_query_binds_regular_params_with_bindvalue(): void
    {
        $code = $this->generateCode(
            "-- @name Filter\n-- @returns :many\n" .
            "SELECT * FROM users WHERE id IN (:ids) AND active = :active;"
        );

        $this->assertStringContainsString("bindValue(':active'", $code);
    }

    public function test_mixed_query_spreads_in_params_in_execute(): void
    {
        $code = $this->generateCode(
            "-- @name Filter\n-- @returns :many\n" .
            "SELECT * FROM users WHERE id IN (:ids) AND active = :active;"
        );

        $this->assertStringContainsString('execute([...$ids])', $code);
    }

    public function test_mixed_query_signature_has_array_then_scalar(): void
    {
        $code = $this->generateCode(
            "-- @name Filter\n-- @returns :many\n" .
            "SELECT * FROM users WHERE id IN (:ids) AND active = :active;"
        );

        // Extract just the function signature line
        preg_match('/public function filter\([^)]+\)/', $code, $m);
        $sig = $m[0] ?? '';

        $arrPos    = strpos($sig, 'array $ids');
        $activePos = strpos($sig, '$active');

        $this->assertNotFalse($arrPos,    'array $ids not found in signature');
        $this->assertNotFalse($activePos, '$active not found in signature');
        $this->assertLessThan($activePos, $arrPos, 'array $ids should appear before $active');
    }

    // =========================================================================
    // Multiple IN params
    // =========================================================================

    public function test_multiple_in_params_both_expanded(): void
    {
        $code = $this->generateCode(
            "-- @name Filter\n-- @returns :many\n" .
            "SELECT * FROM users WHERE id IN (:ids) AND role_id IN (:roleIds);"
        );

        $this->assertStringContainsString('$__ph_ids',     $code);
        $this->assertStringContainsString('$__ph_roleIds', $code);
    }

    public function test_multiple_in_params_both_in_execute(): void
    {
        $code = $this->generateCode(
            "-- @name Filter\n-- @returns :many\n" .
            "SELECT * FROM users WHERE id IN (:ids) AND role_id IN (:roleIds);"
        );

        $this->assertStringContainsString('...$ids', $code);
        $this->assertStringContainsString('...$roleIds', $code);
    }

    // =========================================================================
    // :one and :exec with IN()
    // =========================================================================

    public function test_in_param_works_with_exec_return_type(): void
    {
        $code = $this->generateCode(
            "-- @name DeleteByIds\n-- @returns :exec\n" .
            "DELETE FROM users WHERE id IN (:ids);"
        );

        $this->assertStringContainsString('array $ids', $code);
        $this->assertStringContainsString('$__ph_ids', $code);
    }

    public function test_in_param_works_with_opt_return_type(): void
    {
        $code = $this->generateCode(
            "-- @name FindInIds\n-- @returns :opt\n" .
            "SELECT * FROM users WHERE id IN (:ids) AND email = :email LIMIT 1;"
        );

        $this->assertStringContainsString('array $ids', $code);
        $this->assertStringContainsString('$__ph_ids',  $code);
    }

    // =========================================================================
    // Interface generation — IN params
    // =========================================================================

    public function test_interface_shows_array_type_for_in_param(): void
    {
        $ig      = new InterfaceGenerator('App');
        $queries = $this->analyze(
            "-- @name GetByIds\n-- @returns :many\nSELECT * FROM users WHERE id IN (:ids);"
        );
        $qg   = new QueryGenerator(
            $this->catalog, $this->mapper,
            new ResultDtoGenerator('App'), 'App',
            generateInterfaces: true,
            interfaceGen: $ig,
        );

        $files = $qg->generateInterfaces($queries);
        $code  = $files['UserQueryInterface']['code'];

        $this->assertStringContainsString('array $ids', $code);
    }
}
