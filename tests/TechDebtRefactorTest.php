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
use SqlcPhp\Parser\ReturnType;
use SqlcPhp\Parser\SchemaParser;
use SqlcPhp\Resolver\ColumnResolver;
use SqlcPhp\Resolver\ExpressionTypeResolver;
use SqlcPhp\Resolver\ParamResolver;
use SqlcPhp\Rewriter\SqlRewriter;
use SqlcPhp\TypeMapper\MySQLTypeMapper;

/**
 * Tech-debt refactoring tests — v2.8.5
 *
 * Documents and guards the three architectural improvements:
 *
 *   Fix A — renderBindings($stmtVar)
 *     renderBindings() accepts an explicit statement variable name.
 *     renderPaginateMethod() calls it with '$__countStmt' and '$__stmt'.
 *     No str_replace() hacks anywhere in the generator.
 *
 *   Fix B — renderPaginateCore() shared method
 *     Both renderPaginateMethod() and renderSearchablePaginateMethod()
 *     delegate to one renderPaginateCore(). No duplicated COUNT+PAGE body.
 *
 *   Fix C — InterfaceGenerator strategy dispatch
 *     renderMethodSignature() uses match() on ReturnType with one dedicated
 *     renderer per return-type family. Adding a new return type requires
 *     adding one method and one match arm — the router never changes.
 */
class TechDebtRefactorTest extends TestCase
{
    private SchemaCatalog   $catalog;
    private MySQLTypeMapper $mapper;
    private QueryParser     $parser;
    private QueryAnalyzer   $analyzer;
    private QueryGenerator  $qg;
    private InterfaceGenerator $ig;
    private QueryGenerator  $qgWithIg;

    protected function setUp(): void
    {
        $schema = <<<SQL
            CREATE TABLE users (
                id         INT           AUTO_INCREMENT PRIMARY KEY,
                email      VARCHAR(100)  NOT NULL,
                name       VARCHAR(100)  NULL,
                active     TINYINT       NOT NULL DEFAULT 1,
                country_id INT           NULL
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
        $this->ig       = new InterfaceGenerator('App');
        $this->qgWithIg = new QueryGenerator($this->catalog, $this->mapper, $dtoGen, 'App', true, $this->ig);
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

    private function iface(string $sql): string
    {
        $q     = $this->analyze($sql);
        $files = $this->qgWithIg->generateInterfaces($q);
        return current($files)['code'];
    }

    // =========================================================================
    // Fix A — renderBindings($stmtVar) — no str_replace hacks
    // =========================================================================

    public function test_fix_a_render_bindings_default_targets_stmt(): void
    {
        // Default behaviour must remain unchanged for all non-paginate methods
        $code = $this->code(
            "-- @name GetUser\n-- @returns :one\nSELECT * FROM users WHERE id = :id;"
        );
        $this->assertStringContainsString('$stmt->bindValue(', $code);
        $this->assertStringNotContainsString('$__stmt', $code);
    }

    public function test_fix_a_paginated_count_binds_to_count_stmt(): void
    {
        $code = $this->code(
            "-- @name ListUsers\n-- @optional active\n-- @returns :paginated\n" .
            "SELECT * FROM users WHERE active = :active;"
        );

        $countStart = (int) strpos($code, '$__countStmt = $this->pdo->prepare(');
        $countEnd   = (int) strpos($code, '$__countStmt->execute()');
        $countBlock = substr($code, $countStart, $countEnd - $countStart);

        $this->assertStringContainsString('$__countStmt->bindValue(', $countBlock,
            'Fix A: count block must use $__countStmt->bindValue()');
        $this->assertStringNotContainsString("\$stmt->bindValue(", $countBlock,
            'Fix A: count block must not reference bare $stmt');
    }

    public function test_fix_a_paginated_page_binds_to_page_stmt(): void
    {
        $code = $this->code(
            "-- @name ListUsers\n-- @optional active\n-- @returns :paginated\n" .
            "SELECT * FROM users WHERE active = :active;"
        );

        $pageStart = (int) strpos($code, '$__stmt = $this->pdo->prepare(');
        $pageEnd   = (int) strpos($code, '$__stmt->execute()');
        $pageBlock = substr($code, $pageStart, $pageEnd - $pageStart);

        $this->assertStringContainsString('$__stmt->bindValue(', $pageBlock);
        $stripped = str_replace('$__stmt', '', $pageBlock);
        $this->assertStringNotContainsString('$stmt', $stripped,
            'Fix A: page block must not reference bare $stmt');
    }

    public function test_fix_a_optional_chk_param_bound_to_correct_stmt(): void
    {
        // @optional generates both :param and :param_chk bindings.
        // Both must target the correct statement variable in :paginated.
        $code = $this->code(
            "-- @name ListUsers\n-- @optional active\n-- @returns :paginated\n" .
            "SELECT * FROM users WHERE active = :active;"
        );

        $countStart = (int) strpos($code, '$__countStmt = $this->pdo->prepare(');
        $countEnd   = (int) strpos($code, '$__countStmt->execute()');
        $countBlock = substr($code, $countStart, $countEnd - $countStart);

        // :active_chk must appear in count block, bound to $__countStmt
        $this->assertStringContainsString("__countStmt->bindValue(':active_chk'", $countBlock);
    }

    public function test_fix_a_paginated_no_params_emits_no_bare_stmt(): void
    {
        $code        = $this->code("-- @name ListAll\n-- @returns :paginated\nSELECT * FROM users;");
        $methodStart = (int) strpos($code, 'function listAll');
        $stripped    = str_replace(['$__countStmt', '$__stmt'], '', substr($code, $methodStart));
        $this->assertStringNotContainsString('$stmt', $stripped);
    }

    public function test_fix_a_searchable_paginated_criteria_bound_to_correct_stmts(): void
    {
        $q    = $this->analyze(
            "-- @name ListUsers\n-- @searchable\n-- @returns :paginated\nSELECT * FROM users;"
        );
        $code = $this->qg->generate($q)['UserQuery']['code'];
        $this->assertStringContainsString('$criteria?->bindAll($__countStmt)', $code);
        $this->assertStringContainsString('$criteria?->bindAll($__stmt)', $code);
    }

    // =========================================================================
    // Fix B — renderPaginateCore() — no duplicated COUNT+PAGE body
    // =========================================================================

    public function test_fix_b_plain_paginated_count_before_page(): void
    {
        $code = $this->code("-- @name ListUsers\n-- @returns :paginated\nSELECT * FROM users;");

        $countPos  = strpos($code, '$__countStmt = $this->pdo->prepare(');
        $pagePos   = strpos($code, '$__stmt = $this->pdo->prepare(');
        $resultPos = strpos($code, 'new PaginatedResult(');

        $this->assertNotFalse($countPos);
        $this->assertNotFalse($pagePos);
        $this->assertNotFalse($resultPos);
        $this->assertLessThan($pagePos, $countPos, 'Fix B: COUNT before PAGE');
        $this->assertLessThan($resultPos, $pagePos, 'Fix B: PAGE before PaginatedResult');
    }

    public function test_fix_b_searchable_paginated_same_structure(): void
    {
        $q    = $this->analyze(
            "-- @name ListUsers\n-- @searchable\n-- @returns :paginated\nSELECT * FROM users;"
        );
        $code = $this->qg->generate($q)['UserQuery']['code'];

        $countPos  = strpos($code, '$__countStmt = $this->pdo->prepare(');
        $pagePos   = strpos($code, '$__stmt = $this->pdo->prepare(');
        $resultPos = strpos($code, 'new PaginatedResult(');

        $this->assertLessThan($pagePos, $countPos, 'Fix B: COUNT before PAGE (searchable)');
        $this->assertLessThan($resultPos, $pagePos, 'Fix B: PAGE before PaginatedResult (searchable)');
    }

    public function test_fix_b_identical_paginated_result_fields_both_variants(): void
    {
        $plain = $this->code("-- @name ListUsers\n-- @returns :paginated\nSELECT * FROM users;");
        $q     = $this->analyze(
            "-- @name ListUsers\n-- @searchable\n-- @returns :paginated\nSELECT * FROM users;"
        );
        $searchable = $this->qg->generate($q)['UserQuery']['code'];

        foreach (['items:', 'total:', 'limit:', 'offset:', 'pages:', 'hasMore:'] as $field) {
            $this->assertStringContainsString($field, $plain);
            $this->assertStringContainsString($field, $searchable);
        }
    }

    public function test_fix_b_identical_formulas_both_variants(): void
    {
        $plain = $this->code("-- @name ListUsers\n-- @returns :paginated\nSELECT * FROM users;");
        $q     = $this->analyze(
            "-- @name ListUsers\n-- @searchable\n-- @returns :paginated\nSELECT * FROM users;"
        );
        $searchable = $this->qg->generate($q)['UserQuery']['code'];

        $pagesFormula  = '$__total > 0 && $limit > 0 ? (int) ceil($__total / $limit) : 0';
        $hasMoreFormula = '$offset + count($__items) < $__total';

        $this->assertStringContainsString($pagesFormula,   $plain);
        $this->assertStringContainsString($pagesFormula,   $searchable);
        $this->assertStringContainsString($hasMoreFormula, $plain);
        $this->assertStringContainsString($hasMoreFormula, $searchable);
    }

    public function test_fix_b_each_method_has_exactly_one_paginated_result_construction(): void
    {
        $plain = $this->code("-- @name ListUsers\n-- @returns :paginated\nSELECT * FROM users;");
        $q     = $this->analyze(
            "-- @name ListUsers\n-- @searchable\n-- @returns :paginated\nSELECT * FROM users;"
        );
        $searchable = $this->qg->generate($q)['UserQuery']['code'];

        $this->assertSame(1, substr_count($plain, 'new PaginatedResult('),
            'Fix B: plain :paginated must have exactly one PaginatedResult construction');
        $this->assertSame(1, substr_count($searchable, 'new PaginatedResult('),
            'Fix B: @searchable :paginated must have exactly one PaginatedResult construction');
    }

    // =========================================================================
    // Fix C — InterfaceGenerator strategy dispatch
    // =========================================================================

    public function test_fix_c_uses_match_not_if_elseif(): void
    {
        $src = file_get_contents(dirname(__DIR__) . '/src/Generator/InterfaceGenerator.php');
        $this->assertStringContainsString('return match ($query->returns)', $src);
        $this->assertStringNotContainsString('elseif ($query->returns', $src);
    }

    public function test_fix_c_all_dedicated_renderers_exist(): void
    {
        $src = file_get_contents(dirname(__DIR__) . '/src/Generator/InterfaceGenerator.php');
        $expected = [
            'renderReturningSignature', 'renderPaginatedSignature',
            'renderManyPaginatedSignature', 'renderManySignature',
            'renderOneSignature', 'renderOptSignature',
            'renderExecSignature', 'renderBatchSignature',
            'renderTransactionSignature', 'renderFallbackSignature',
        ];
        foreach ($expected as $method) {
            $this->assertStringContainsString("private function {$method}(", $src,
                "Fix C: dedicated renderer '{$method}' must exist");
        }
    }

    public function test_fix_c_match_covers_all_return_type_cases(): void
    {
        $src = file_get_contents(dirname(__DIR__) . '/src/Generator/InterfaceGenerator.php');
        foreach (ReturnType::cases() as $case) {
            $this->assertStringContainsString("ReturnType::{$case->name}", $src,
                "Fix C: match must explicitly reference ReturnType::{$case->name}");
        }
    }

    public function test_fix_c_many_signature(): void
    {
        $this->assertStringContainsString('): array;',
            $this->iface("-- @name ListUsers\n-- @returns :many\nSELECT * FROM users;"));
    }

    public function test_fix_c_many_paginated_signature(): void
    {
        $iface = $this->iface("-- @name ListUsers\n-- @returns :many-paginated\nSELECT * FROM users;");
        $this->assertStringContainsString('?int $limit = null, int $offset = 0', $iface);
        $this->assertStringContainsString('): array;', $iface);
    }

    public function test_fix_c_paginated_signature_has_use_and_type(): void
    {
        $iface = $this->iface("-- @name ListUsers\n-- @returns :paginated\nSELECT * FROM users;");
        $this->assertStringContainsString('use SqlcPhp\\Query\\PaginatedResult;', $iface);
        $this->assertStringContainsString('int $limit = 10, int $offset = 0', $iface);
        $this->assertStringContainsString('): PaginatedResult;', $iface);
    }

    public function test_fix_c_one_signature(): void
    {
        $this->assertStringContainsString('public function getUser(int $id): User;',
            $this->iface("-- @name GetUser\n-- @returns :one\nSELECT * FROM users WHERE id = :id;"));
    }

    public function test_fix_c_opt_signature(): void
    {
        $this->assertStringContainsString('): ?User;',
            $this->iface("-- @name FindUser\n-- @returns :opt\nSELECT * FROM users WHERE email = :email;"));
    }

    public function test_fix_c_exec_signature(): void
    {
        $this->assertStringContainsString('): void;',
            $this->iface("-- @name DeleteUser\n-- @returns :exec\nDELETE FROM users WHERE id = :id;"));
    }

    public function test_fix_c_batch_signature(): void
    {
        $this->assertStringContainsString('): int;',
            $this->iface("-- @name InsertUsers\n-- @returns :batch\nINSERT INTO users (email, active) VALUES (:email, :active);"));
    }

    public function test_fix_c_returning_signature(): void
    {
        $this->assertStringContainsString('): User;',
            $this->iface(
                "-- @name CreateUser\n-- @returning\n-- @returns :one\n" .
                "INSERT INTO users (email, active) VALUES (:email, :active);"
            ));
    }

    public function test_fix_c_searchable_adds_criteria_param(): void
    {
        $iface = $this->iface("-- @name ListUsers\n-- @searchable\n-- @returns :many\nSELECT * FROM users;");
        $this->assertStringContainsString('?UserCriteria $criteria = null', $iface);
    }

    public function test_fix_c_counted_adds_count_method(): void
    {
        $iface = $this->iface("-- @name ListUsers\n-- @counted\n-- @returns :many-paginated\nSELECT * FROM users;");
        $this->assertStringContainsString('listUsersCount(', $iface);
        $this->assertStringContainsString('): int;', $iface);
    }

    public function test_fix_c_searchable_paginated_has_criteria_limit_offset(): void
    {
        $iface = $this->iface("-- @name ListUsers\n-- @searchable\n-- @returns :paginated\nSELECT * FROM users;");
        $this->assertStringContainsString('?UserCriteria $criteria = null', $iface);
        $this->assertStringContainsString('int $limit = 10', $iface);
        $this->assertStringContainsString('): PaginatedResult;', $iface);
    }

    // =========================================================================
    // Integration — all three fixes work together
    // =========================================================================

    public function test_all_fixes_optional_paginated_interface(): void
    {
        $q = $this->analyze(
            "-- @name ListActiveUsers\n-- @optional active\n-- @optional country_id\n" .
            "-- @returns :paginated\n" .
            "SELECT * FROM users WHERE active = :active AND country_id = :country_id;"
        );

        // Fix A
        $code = $this->qg->generate($q)['UserQuery']['code'];
        $countStart = (int) strpos($code, '$__countStmt = $this->pdo->prepare(');
        $countEnd   = (int) strpos($code, '$__countStmt->execute()');
        $countBlock = substr($code, $countStart, $countEnd - $countStart);
        $this->assertStringNotContainsString("\$stmt->bindValue(", $countBlock);

        // Fix B
        $this->assertSame(1, substr_count($code, 'new PaginatedResult('));
        $this->assertSame(1, substr_count($code, 'ceil($__total / $limit)'));

        // Fix C
        $files = $this->qgWithIg->generateInterfaces($q);
        $iface = $files['UserQueryInterface']['code'];
        $this->assertStringContainsString('use SqlcPhp\\Query\\PaginatedResult;', $iface);
        $this->assertStringContainsString('): PaginatedResult;', $iface);
    }

    // =========================================================================
    // Version
    // =========================================================================

    public function test_version_is_2_8_5(): void
    {
        $this->assertSame('2.9.6', \SqlcPhp\Version::VERSION);
    }
}
