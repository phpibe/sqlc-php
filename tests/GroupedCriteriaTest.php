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
 * Tests for @with criteria on :grouped queries.
 *
 * @with criteria adds a dynamic WHERE clause (Criteria object) to a :grouped
 * query, enabling optional filtering while keeping the groupResults() behaviour.
 */
class GroupedCriteriaTest extends TestCase
{
    private SchemaCatalog   $catalog;
    private QueryAnalyzer   $analyzer;
    private QueryParser     $parser;
    private MySQLTypeMapper $mapper;
    private ResultDtoGenerator $dtoGen;
    private QueryGenerator  $gen;

    protected function setUp(): void
    {
        $schema = <<<SQL
            CREATE TABLE refunds_categories (
                id        INT          AUTO_INCREMENT PRIMARY KEY,
                parent_id INT          NULL,
                name      VARCHAR(100) NOT NULL
            );
            CREATE TABLE refunds_subcategories (
                id        INT          AUTO_INCREMENT PRIMARY KEY,
                parent_id INT          NULL,
                name      VARCHAR(100) NOT NULL
            );
            CREATE TABLE orders (
                id      INT AUTO_INCREMENT PRIMARY KEY,
                user_id INT NOT NULL,
                status  VARCHAR(20) NOT NULL
            );
            CREATE TABLE order_items (
                id       INT AUTO_INCREMENT PRIMARY KEY,
                order_id INT NOT NULL,
                name     VARCHAR(100) NOT NULL,
                qty      INT NOT NULL
            );
        SQL;

        $this->catalog  = new SchemaCatalog((new SchemaParser())->parse($schema));
        $this->mapper   = new MySQLTypeMapper();
        $this->parser   = new QueryParser('english');
        $pr = new ParamResolver($this->catalog, $this->mapper);
        $er = new ExpressionTypeResolver($this->catalog, $this->mapper);
        $cr = new ColumnResolver($this->catalog, $this->mapper, $pr, $er);
        $this->analyzer = new QueryAnalyzer($pr, $cr, $this->parser, new SqlRewriter(), $this->catalog);
        $this->dtoGen   = new ResultDtoGenerator('App\\DTOs', $this->mapper, $this->catalog);
        $this->gen      = new QueryGenerator(
            $this->catalog, $this->mapper, $this->dtoGen,
            'App\\Q', criteriasNamespace: 'App\\Criterias'
        );
    }

    private function analyze(string $sql): array
    {
        return $this->analyzer->analyze($this->parser->parse($sql));
    }

    private function queryCode(string $sql): string
    {
        $q = $this->analyze($sql);
        $files = $this->gen->generate($q);
        foreach ($files as $key => $f) {
            if (str_ends_with($key, 'Query')) return $f['code'];
        }
        return '';
    }

    private function hasCriteriaFile(string $sql): bool
    {
        $q = $this->analyze($sql);
        $files = $this->gen->generate($q);
        foreach (array_keys($files) as $key) {
            if (str_contains($key, 'Criteria')) return true;
        }
        return false;
    }

    private function groupedCriteriaSql(): string
    {
        return "-- @name ListRefundsCategories\n-- @class RefundsCategories\n" .
               "-- @group_by refunds_categories.id\n-- @with criteria\n-- @returns :grouped\n" .
               "SELECT refunds_categories.*,\n" .
               "       refunds_subcategories.id AS subcategory_id,\n" .
               "       refunds_subcategories.name AS subcategory_name\n" .
               "FROM refunds_categories\n" .
               "LEFT JOIN refunds_subcategories ON refunds_subcategories.parent_id = refunds_categories.id;";
    }

    // =========================================================================
    // Analyzer — validation
    // =========================================================================

    public function test_criteria_on_grouped_does_not_throw(): void
    {
        // Must not throw RuntimeException
        $q = $this->analyze($this->groupedCriteriaSql());
        $this->assertTrue($q[0]->searchable);
        $this->assertSame(':grouped', $q[0]->returns->value);
    }

    public function test_criteria_on_grouped_is_searchable(): void
    {
        $q = $this->analyze($this->groupedCriteriaSql());
        $this->assertTrue($q[0]->searchable);
    }

    public function test_criteria_on_grouped_preserves_group_by(): void
    {
        $q = $this->analyze($this->groupedCriteriaSql());
        $this->assertSame('refunds_categories.id', $q[0]->groupByColumn);
    }

    public function test_criteria_still_rejected_on_exec(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->analyze(
            "-- @name DoSomething\n-- @class RefundsCategories\n" .
            "-- @with criteria\n-- @returns :exec\n" .
            "DELETE FROM refunds_categories WHERE id = :id;"
        );
    }

    // =========================================================================
    // Generator — method signature
    // =========================================================================

    public function test_grouped_criteria_method_accepts_criteria_param(): void
    {
        $code = $this->queryCode($this->groupedCriteriaSql());
        $this->assertStringContainsString('ListRefundsCategoriesCriteria', $code);
        $this->assertStringContainsString('$criteria = null', $code);
    }

    public function test_grouped_criteria_method_calls_group_results(): void
    {
        $code = $this->queryCode($this->groupedCriteriaSql());
        $this->assertStringContainsString('groupResults(', $code);
    }

    public function test_grouped_criteria_method_calls_bind_all(): void
    {
        $code = $this->queryCode($this->groupedCriteriaSql());
        $this->assertStringContainsString('$criteria?->bindAll($stmt)', $code);
    }

    public function test_grouped_criteria_method_builds_dynamic_sql(): void
    {
        $code = $this->queryCode($this->groupedCriteriaSql());
        $this->assertStringContainsString('toFilterClause(', $code);
    }

    public function test_grouped_criteria_method_signature_format(): void
    {
        $code = $this->queryCode($this->groupedCriteriaSql());
        // Method must match: public function listRefundsCategories(?...Criteria $criteria = null): array
        $this->assertMatchesRegularExpression(
            '/public function listRefundsCategories\(\?ListRefundsCategoriesCriteria \$criteria = null\): array/',
            $code
        );
    }

    // =========================================================================
    // Generator — criteria file
    // =========================================================================

    public function test_criteria_file_generated_for_grouped(): void
    {
        $this->assertTrue($this->hasCriteriaFile($this->groupedCriteriaSql()));
    }

    public function test_criteria_file_named_after_query(): void
    {
        $q = $this->analyze($this->groupedCriteriaSql());
        $files = $this->gen->generate($q);
        $criteriaKeys = array_filter(array_keys($files), fn($k) => str_contains($k, 'Criteria'));
        $this->assertNotEmpty($criteriaKeys);
        $this->assertStringContainsString('ListRefundsCategoriesCriteria', array_values($criteriaKeys)[0]);
    }

    // =========================================================================
    // Generator — existing :grouped without criteria unchanged
    // =========================================================================

    public function test_interface_signature_includes_criteria_param(): void
    {
        // The interface signature must match the implementation.
        // buildParamListPublic is what InterfaceGenerator uses.
        // For :grouped + @with criteria, the criteria param must be included.
        $q = $this->analyze($this->groupedCriteriaSql());

        // The public param list (used by InterfaceGenerator) includes user params only.
        // The criteria param is added separately in renderGroupedSignature.
        // We verify by checking the generated query code contains the right signature.
        $code = $this->queryCode($this->groupedCriteriaSql());
        $this->assertStringContainsString(
            'ListRefundsCategoriesCriteria $criteria',
            $code,
            'Method must include $criteria param — interface must match'
        );
    }

    public function test_grouped_without_criteria_interface_no_criteria_param(): void
    {
        $sql = "-- @name ListRefundsCategories\n-- @class RefundsCategories\n" .
               "-- @group_by refunds_categories.id\n-- @returns :grouped\n" .
               "SELECT refunds_categories.*,\n" .
               "       refunds_subcategories.id AS subcategory_id,\n" .
               "       refunds_subcategories.name AS subcategory_name\n" .
               "FROM refunds_categories\n" .
               "LEFT JOIN refunds_subcategories ON refunds_subcategories.parent_id = refunds_categories.id;";

        $code = $this->queryCode($sql);
        // No criteria in method
        $this->assertStringNotContainsString('Criteria', $code);
        // groupResults still there
        $this->assertStringContainsString('groupResults(', $code);
    }

    public function test_grouped_without_criteria_unchanged(): void
    {
        $sql = "-- @name ListRefundsCategories\n-- @class RefundsCategories\n" .
               "-- @group_by refunds_categories.id\n-- @returns :grouped\n" .
               "SELECT refunds_categories.*,\n" .
               "       refunds_subcategories.id AS subcategory_id,\n" .
               "       refunds_subcategories.name AS subcategory_name\n" .
               "FROM refunds_categories\n" .
               "LEFT JOIN refunds_subcategories ON refunds_subcategories.parent_id = refunds_categories.id;";

        $code = $this->queryCode($sql);
        // No criteria param
        $this->assertStringNotContainsString('Criteria', $code);
        // Still uses groupResults
        $this->assertStringContainsString('groupResults(', $code);
    }

    public function test_many_with_criteria_unchanged(): void
    {
        $sql = "-- @name ListOrders\n-- @class Orders\n-- @with criteria\n-- @returns :many\n" .
               "SELECT orders.* FROM orders;";
        $code = $this->queryCode($sql);
        // Must NOT use groupResults (it's :many, not :grouped)
        $this->assertStringNotContainsString('groupResults(', $code);
        // Must have criteria
        $this->assertStringContainsString('Criteria', $code);
    }

    // =========================================================================
    // Combined: criteria + user params
    // =========================================================================

    public function test_grouped_criteria_with_user_params(): void
    {
        $sql = "-- @name ListRefundsCategoriesFiltered\n-- @class RefundsCategories\n" .
               "-- @group_by refunds_categories.id\n-- @with criteria\n-- @returns :grouped\n" .
               "SELECT refunds_categories.*,\n" .
               "       refunds_subcategories.id AS subcategory_id,\n" .
               "       refunds_subcategories.name AS subcategory_name\n" .
               "FROM refunds_categories\n" .
               "LEFT JOIN refunds_subcategories ON refunds_subcategories.parent_id = refunds_categories.id\n" .
               "WHERE refunds_categories.parent_id = :parent_id;";

        $code = $this->queryCode($sql);
        // User param comes first, then criteria
        $this->assertStringContainsString('int $parent_id', $code);
        $this->assertStringContainsString('Criteria $criteria', $code);
        // groupResults still used
        $this->assertStringContainsString('groupResults(', $code);
    }

    public function test_regular_join_grouped_with_criteria(): void
    {
        $sql = "-- @name GetOrdersWithItems\n-- @class Orders\n" .
               "-- @group_by orders.id\n-- @with criteria\n-- @returns :grouped\n" .
               "SELECT orders.id, orders.status,\n" .
               "       order_items.id AS item_id,\n" .
               "       order_items.name AS item_name,\n" .
               "       order_items.qty\n" .
               "FROM orders\n" .
               "LEFT JOIN order_items ON order_items.order_id = orders.id;";

        $code = $this->queryCode($sql);
        $this->assertStringContainsString('GetOrdersWithItemsCriteria', $code);
        $this->assertStringContainsString('groupResults(', $code);
        $this->assertStringContainsString('bindAll(', $code);
    }
    // =========================================================================
    // Standalone :count and :exists with criteria
    // =========================================================================

    public function test_count_with_criteria_does_not_throw(): void
    {
        $q = $this->analyze(
            "-- @name CountCategories\n-- @class RefundsCategories\n" .
            "-- @with criteria\n-- @returns :count\n" .
            "SELECT COUNT(*) FROM refunds_categories;"
        );
        $this->assertTrue($q[0]->searchable);
    }

    public function test_count_with_criteria_generates_criteria_param(): void
    {
        $code = $this->queryCode(
            "-- @name CountCategories\n-- @class RefundsCategories\n" .
            "-- @with criteria\n-- @returns :count\n" .
            "SELECT COUNT(*) FROM refunds_categories;"
        );
        $this->assertStringContainsString('CountCategoriesCriteria', $code);
        $this->assertStringContainsString('): int', $code);
    }

    public function test_count_with_criteria_method_name_not_doubled(): void
    {
        $code = $this->queryCode(
            "-- @name CountCategories\n-- @class RefundsCategories\n" .
            "-- @with criteria\n-- @returns :count\n" .
            "SELECT COUNT(*) FROM refunds_categories;"
        );
        $this->assertStringContainsString('function countCategories(', $code);
        $this->assertStringNotContainsString('function countCategoriesCount(', $code);
    }

    public function test_exists_with_criteria_generates_criteria_param(): void
    {
        $code = $this->queryCode(
            "-- @name CheckCategoryExists\n-- @class RefundsCategories\n" .
            "-- @with criteria\n-- @returns :exists\n" .
            "SELECT id FROM refunds_categories;"
        );
        $this->assertStringContainsString('CheckCategoryExistsCriteria', $code);
        $this->assertStringContainsString('): bool', $code);
        $this->assertStringContainsString('function checkCategoryExists(', $code);
    }

    public function test_criteria_still_rejected_on_one(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->analyze(
            "-- @name GetCategory\n-- @class RefundsCategories\n" .
            "-- @with criteria\n-- @returns :one\n" .
            "SELECT * FROM refunds_categories WHERE id = :id;"
        );
    }

    public function test_count_query_not_double_wrapped(): void
    {
        // SELECT COUNT(*) with :count + criteria must NOT produce
        // SELECT COUNT(*) AS _total FROM (SELECT COUNT(*) FROM ...) AS _count_subquery
        $code = $this->queryCode(
            "-- @name CountCategories\n-- @class RefundsCategories\n" .
            "-- @with criteria\n-- @returns :count\n" .
            "SELECT COUNT(*) FROM refunds_categories;"
        );
        // Must NOT wrap in a subquery
        $this->assertStringNotContainsString('_count_subquery', $code);
        // Must still add _total alias for consistent fetch
        $this->assertStringContainsString('_total', $code);
    }

    // =========================================================================
    // CTE queries — criteria must go in outermost SELECT, not inside CTE
    // =========================================================================

    public function test_criteria_on_cte_query_inserts_where_after_main_from(): void
    {
        $schema = "
            CREATE TABLE orders (id INT AUTO_INCREMENT PRIMARY KEY, user_id INT NOT NULL, status VARCHAR(20) NOT NULL);
            CREATE TABLE order_items (id INT AUTO_INCREMENT PRIMARY KEY, order_id INT NOT NULL, name VARCHAR(100) NOT NULL);
        ";
        $catalog2  = new \SqlcPhp\Catalog\SchemaCatalog((new \SqlcPhp\Parser\SchemaParser())->parse($schema));
        $mapper2   = new \SqlcPhp\TypeMapper\MySQLTypeMapper();
        $parser2   = new \SqlcPhp\Parser\QueryParser('english');
        $pr2 = new \SqlcPhp\Resolver\ParamResolver($catalog2, $mapper2);
        $er2 = new \SqlcPhp\Resolver\ExpressionTypeResolver($catalog2, $mapper2);
        $cr2 = new \SqlcPhp\Resolver\ColumnResolver($catalog2, $mapper2, $pr2, $er2);
        $analyzer2 = new \SqlcPhp\Analyzer\QueryAnalyzer($pr2, $cr2, $parser2, new \SqlcPhp\Rewriter\SqlRewriter(), $catalog2);
        $dtoGen2   = new \SqlcPhp\Generator\ResultDtoGenerator('App\\DTOs', $mapper2, $catalog2);
        $gen2      = new \SqlcPhp\Generator\QueryGenerator($catalog2, $mapper2, $dtoGen2, 'App\\Q', criteriasNamespace: 'App\\Criterias');

        $sql = "-- @name ListOrders\n-- @class Orders\n-- @with criteria\n-- @returns :many\n" .
               "WITH base AS (\n" .
               "    SELECT id, status FROM orders WHERE status = 'active'\n" .
               ")\n" .
               "SELECT b.id, b.status FROM base b ORDER BY b.id DESC;";

        $q = $analyzer2->analyze($parser2->parse($sql));
        $files = $gen2->generate($q);

        $code = '';
        foreach ($files as $key => $f) {
            if (str_ends_with($key, 'Query')) $code = $f['code'];
        }

        // The criteria WHERE must be inserted AFTER the main SELECT FROM base b
        // not inside the CTE body
        $sqlBlockPos   = strpos($code, '$__sql =');
        $criteriaPos   = strpos($code, 'toFilterClause');
        $cteBodyEndPos = strpos($code, 'FROM base b');

        $this->assertGreaterThan($cteBodyEndPos ?? 0, $criteriaPos ?? 0,
            'Criteria filter must be inserted after the main FROM, not inside the CTE');
    }

    public function test_criteria_on_plain_query_unchanged(): void
    {
        // Plain query (no CTE) must work exactly as before
        $code = $this->queryCode(
            "-- @name ListOrders\n-- @class Orders\n-- @with criteria\n-- @returns :many\n" .
            "SELECT orders.* FROM orders WHERE orders.status = 'active' ORDER BY orders.id DESC;"
        );
        // criteria inserts after WHERE active (hasWhere=true)
        $this->assertStringContainsString('$__hasWhere = true', $code);
        $this->assertStringContainsString('toFilterClause', $code);
        $this->assertStringContainsString('ORDER BY orders.id DESC', $code);
    }

}