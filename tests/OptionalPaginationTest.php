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

/**
 * Tests for the :many-paginated optional LIMIT behavior (v2.5.2).
 *
 * Behavior:
 *   - listUsers()             → all rows (no LIMIT/OFFSET applied)
 *   - listUsers(20)           → first 20 rows (LIMIT 20 OFFSET 0)
 *   - listUsers(20, 40)       → rows 41-60 (LIMIT 20 OFFSET 40)
 *   - listUsers(null, 40)     → all rows (offset meaningless without limit)
 */
class OptionalPaginationTest extends TestCase
{
    private SchemaCatalog  $catalog;
    private MySQLTypeMapper $mapper;
    private QueryParser    $parser;
    private QueryAnalyzer  $analyzer;
    private QueryGenerator $qg;

    protected function setUp(): void
    {
        $schema = <<<SQL
            CREATE TABLE users (
                id     INT          AUTO_INCREMENT PRIMARY KEY,
                email  VARCHAR(100) NOT NULL,
                active TINYINT      NOT NULL DEFAULT 1
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
        return $this->qg->generate($this->analyze($sql))['UserQuery']['code'];
    }

    // =========================================================================
    // Signature
    // =========================================================================

    public function test_limit_param_is_nullable_int(): void
    {
        $code = $this->code("-- @name ListUsers\n-- @returns :many-paginated\nSELECT * FROM users;");
        $this->assertStringContainsString('?int $limit = null', $code);
    }

    public function test_offset_param_is_int_with_zero_default(): void
    {
        $code = $this->code("-- @name ListUsers\n-- @returns :many-paginated\nSELECT * FROM users;");
        $this->assertStringContainsString('int $offset = 0', $code);
    }

    public function test_user_params_come_before_pagination_params(): void
    {
        $code = $this->code(
            "-- @name ListByActive\n-- @returns :many-paginated\n" .
            "SELECT * FROM users WHERE active = :active;"
        );
        $activePos = strpos($code, '$active');
        $limitPos  = strpos($code, '$limit');
        $this->assertNotFalse($activePos);
        $this->assertNotFalse($limitPos);
        $this->assertLessThan($limitPos, $activePos);
    }

    public function test_method_returns_array(): void
    {
        $code = $this->code("-- @name ListUsers\n-- @returns :many-paginated\nSELECT * FROM users;");
        $this->assertStringContainsString('listUsers(?int $limit = null, int $offset = 0): array', $code);
    }

    // =========================================================================
    // Two code paths exist
    // =========================================================================

    public function test_method_has_if_limit_null_branch(): void
    {
        $code = $this->code("-- @name ListUsers\n-- @returns :many-paginated\nSELECT * FROM users;");
        $this->assertStringContainsString('if ($limit === null)', $code);
    }

    public function test_null_branch_prepares_sql_without_limit_offset(): void
    {
        $code = $this->code("-- @name ListUsers\n-- @returns :many-paginated\nSELECT * FROM users;");

        // The no-limit branch SQL must NOT contain LIMIT/OFFSET placeholders
        $ifPos  = strpos($code, 'if ($limit === null)');
        $elsePos = strpos($code, '} else {');
        $this->assertNotFalse($ifPos);
        $this->assertNotFalse($elsePos);

        $nullBranch = substr($code, $ifPos, $elsePos - $ifPos);
        $this->assertStringNotContainsString(':limit',  $nullBranch);
        $this->assertStringNotContainsString(':offset', $nullBranch);
    }

    public function test_paginated_branch_binds_limit_and_offset(): void
    {
        $code = $this->code("-- @name ListUsers\n-- @returns :many-paginated\nSELECT * FROM users;");

        $elsePos = strpos($code, '} else {');
        $this->assertNotFalse($elsePos);
        $elseBranch = substr($code, $elsePos);

        $this->assertStringContainsString("bindValue(':limit',",  $elseBranch);
        $this->assertStringContainsString("bindValue(':offset',", $elseBranch);
    }

    public function test_paginated_branch_sql_has_limit_offset(): void
    {
        $code = $this->code("-- @name ListUsers\n-- @returns :many-paginated\nSELECT * FROM users;");

        $elsePos = strpos($code, '} else {');
        $this->assertNotFalse($elsePos);
        $elseBranch = substr($code, $elsePos);

        $this->assertStringContainsString('LIMIT :limit',  $elseBranch);
        $this->assertStringContainsString('OFFSET :offset', $elseBranch);
    }

    public function test_execute_and_fetchall_appear_after_both_branches(): void
    {
        $code = $this->code("-- @name ListUsers\n-- @returns :many-paginated\nSELECT * FROM users;");

        // Both branches end before execute — execute appears once, after the if/else
        $this->assertSame(1, substr_count($code, '$stmt->execute()'));
        $this->assertSame(1, substr_count($code, 'fetchAll'));
    }

    // =========================================================================
    // User params are bound in BOTH branches
    // =========================================================================

    public function test_user_param_bound_in_null_branch(): void
    {
        $code = $this->code(
            "-- @name ListByActive\n-- @returns :many-paginated\n" .
            "SELECT * FROM users WHERE active = :active;"
        );

        $ifPos   = strpos($code, 'if ($limit === null)');
        $elsePos = strpos($code, '} else {');
        $nullBranch = substr($code, $ifPos, $elsePos - $ifPos);

        $this->assertStringContainsString("bindValue(':active'", $nullBranch);
    }

    public function test_user_param_bound_in_paginated_branch(): void
    {
        $code = $this->code(
            "-- @name ListByActive\n-- @returns :many-paginated\n" .
            "SELECT * FROM users WHERE active = :active;"
        );

        $elsePos    = strpos($code, '} else {');
        $executePos = strpos($code, '$stmt->execute()');
        $elseBranch = substr($code, $elsePos, $executePos - $elsePos);

        $this->assertStringContainsString("bindValue(':active'", $elseBranch);
    }

    // =========================================================================
    // @optional params bound in both branches
    // =========================================================================

    public function test_optional_param_chk_bound_in_null_branch(): void
    {
        $code = $this->code(
            "-- @name ListByActive\n-- @optional active\n-- @returns :many-paginated\n" .
            "SELECT * FROM users WHERE active = :active;"
        );

        $ifPos      = strpos($code, 'if ($limit === null)');
        $elsePos    = strpos($code, '} else {');
        $nullBranch = substr($code, $ifPos, $elsePos - $ifPos);

        $this->assertStringContainsString("bindValue(':active_chk'", $nullBranch);
    }

    public function test_optional_param_chk_bound_in_paginated_branch(): void
    {
        $code = $this->code(
            "-- @name ListByActive\n-- @optional active\n-- @returns :many-paginated\n" .
            "SELECT * FROM users WHERE active = :active;"
        );

        $elsePos    = strpos($code, '} else {');
        $executePos = strpos($code, '$stmt->execute()');
        $elseBranch = substr($code, $elsePos, $executePos - $elsePos);

        $this->assertStringContainsString("bindValue(':active_chk'", $elseBranch);
    }

    // =========================================================================
    // Prepared statement cache — two distinct cache keys
    // =========================================================================

    public function test_cache_uses_distinct_keys_for_all_and_paginated(): void
    {
        $mapper  = new MySQLTypeMapper([], new EnumGenerator('App'));
        $dtoGen  = new ResultDtoGenerator('App', $mapper);
        $qg      = new QueryGenerator(
            $this->catalog, $mapper, $dtoGen, 'App',
            false, null, true   // prepared_statement_cache = true
        );

        $queries = $this->analyze(
            "-- @name ListUsers\n-- @returns :many-paginated\nSELECT * FROM users;"
        );
        $code = $qg->generate($queries)['UserQuery']['code'];

        $this->assertStringContainsString("__FUNCTION__ . '_all'",  $code);
        $this->assertStringContainsString("__FUNCTION__ . '_page'", $code);
    }

    public function test_without_cache_uses_direct_prepare_in_both_branches(): void
    {
        $code = $this->code("-- @name ListUsers\n-- @returns :many-paginated\nSELECT * FROM users;");

        // Both branches use $this->pdo->prepare directly
        $this->assertSame(2, substr_count($code, '$this->pdo->prepare('));
        $this->assertStringNotContainsString('$this->stmts', $code);
    }

    // =========================================================================
    // Docblock
    // =========================================================================

    public function test_docblock_documents_nullable_limit(): void
    {
        $code = $this->code("-- @name ListUsers\n-- @returns :many-paginated\nSELECT * FROM users;");
        $this->assertStringContainsString('@param ?int $limit', $code);
        $this->assertStringContainsString('null (default) to return all rows', $code);
    }

    public function test_docblock_documents_offset(): void
    {
        $code = $this->code("-- @name ListUsers\n-- @returns :many-paginated\nSELECT * FROM users;");
        $this->assertStringContainsString('@param int  $offset', $code);
        $this->assertStringContainsString('Ignored when $limit is null', $code);
    }

    // =========================================================================
    // Interface
    // =========================================================================

    public function test_interface_uses_nullable_limit_signature(): void
    {
        $dtoGen = new ResultDtoGenerator('App', $this->mapper);
        $ig     = new InterfaceGenerator('App');
        $qg     = new QueryGenerator($this->catalog, $this->mapper, $dtoGen, 'App', true, $ig);

        $queries = $this->analyze(
            "-- @name ListUsers\n-- @returns :many-paginated\nSELECT * FROM users;"
        );
        $iface = $ig->generate('UserQuery', $queries, $qg);

        $this->assertStringContainsString('?int $limit = null', $iface['code']);
        $this->assertStringNotContainsString('int $limit = 20', $iface['code']);
    }

    // =========================================================================
    // @counted + optional pagination — count method unaffected
    // =========================================================================

    public function test_counted_method_unaffected_by_nullable_limit(): void
    {
        $queries = $this->analyze(
            "-- @name ListUsers\n-- @counted\n-- @returns :many-paginated\nSELECT * FROM users;"
        );
        $code = $this->qg->generate($queries)['UserQuery']['code'];

        // Count method has no $limit parameter at all
        $countPos = strpos($code, 'function listUsersCount');
        $this->assertNotFalse($countPos);
        $countSig = substr($code, $countPos, 60);
        $this->assertStringNotContainsString('$limit', $countSig);
        $this->assertStringContainsString('): int', $countSig);
    }
}
