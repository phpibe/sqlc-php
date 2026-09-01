<?php

declare(strict_types=1);

namespace SqlcPhp\Tests;

use PHPUnit\Framework\TestCase;
use SqlcPhp\Analyzer\QueryAnalyzer;
use SqlcPhp\Catalog\SchemaCatalog;
use SqlcPhp\Generator\ResultDtoGenerator;
use SqlcPhp\Parser\QueryParser;
use SqlcPhp\Parser\SchemaParser;
use SqlcPhp\Resolver\ColumnResolver;
use SqlcPhp\Resolver\ExpressionTypeResolver;
use SqlcPhp\Resolver\ParamResolver;
use SqlcPhp\Rewriter\SqlRewriter;
use SqlcPhp\TypeMapper\MySQLTypeMapper;

/**
 * Tests for :grouped with self-joins.
 *
 * When a table JOINs itself (self-join with alias), all columns resolve
 * to the same tableName. The compiler must use alias vs columnName to
 * distinguish primary table columns from JOIN-side columns.
 */
class GroupedSelfJoinTest extends TestCase
{
    private SchemaCatalog $catalog;
    private QueryAnalyzer $analyzer;
    private QueryParser $parser;
    private ResultDtoGenerator $dtoGen;

    protected function setUp(): void
    {
        $schema = <<<SQL
            CREATE TABLE refunds_categories (
                id        INT          AUTO_INCREMENT PRIMARY KEY,
                parent_id INT          NULL,
                name      VARCHAR(100) NOT NULL,
                icon      VARCHAR(50)  NOT NULL DEFAULT ''
            );
            CREATE TABLE users (
                id         INT          AUTO_INCREMENT PRIMARY KEY,
                name       VARCHAR(100) NOT NULL,
                manager_id INT          NULL
            );
        SQL;

        $this->catalog = new SchemaCatalog((new SchemaParser())->parse($schema));
        $mapper = new MySQLTypeMapper();
        $this->parser = new QueryParser('english');
        $pr = new ParamResolver($this->catalog, $mapper);
        $er = new ExpressionTypeResolver($this->catalog, $mapper);
        $cr = new ColumnResolver($this->catalog, $mapper, $pr, $er);
        $this->analyzer = new QueryAnalyzer($pr, $cr, $this->parser, new SqlRewriter(), $this->catalog);
        $this->dtoGen   = new ResultDtoGenerator('App\\DTOs', $mapper, $this->catalog);
    }

    private function grouped(string $sql): array
    {
        $q = $this->analyzer->analyze($this->parser->parse($sql));
        return $this->dtoGen->generateGrouped($q[0]);
    }

    // =========================================================================
    // Self-join: split by alias vs columnName
    // =========================================================================

    public function test_self_join_generates_item_class(): void
    {
        $r = $this->grouped(
            "-- @name ListCategories\n-- @class RefundsCategories\n" .
            "-- @group_by refunds_categories.id\n-- @returns :grouped\n" .
            "SELECT refunds_categories.id, refunds_categories.name,\n" .
            "       refunds_subcategories.id as subcategory_id,\n" .
            "       refunds_subcategories.name as subcategory_name\n" .
            "FROM refunds_categories\n" .
            "LEFT JOIN refunds_categories as refunds_subcategories\n" .
            "    ON refunds_subcategories.parent_id = refunds_categories.id\n" .
            "WHERE refunds_categories.parent_id IS NULL;"
        );

        $this->assertSame('ListCategoriesItem', $r['itemClass']);
        $this->assertStringContainsString('subcategory_id', $r['itemCode']);
        $this->assertStringContainsString('subcategory_name', $r['itemCode']);
    }

    public function test_self_join_primary_columns_in_row_not_item(): void
    {
        $r = $this->grouped(
            "-- @name ListCategories\n-- @class RefundsCategories\n" .
            "-- @group_by refunds_categories.id\n-- @returns :grouped\n" .
            "SELECT refunds_categories.id, refunds_categories.name,\n" .
            "       refunds_subcategories.id as subcategory_id,\n" .
            "       refunds_subcategories.name as subcategory_name\n" .
            "FROM refunds_categories\n" .
            "LEFT JOIN refunds_categories as refunds_subcategories\n" .
            "    ON refunds_subcategories.parent_id = refunds_categories.id\n" .
            "WHERE refunds_categories.parent_id IS NULL;"
        );

        // Row has id and name (primary), NOT subcategory columns in constructor
        $this->assertStringContainsString('public int $id', $r['code']);
        $this->assertStringContainsString('public string $name', $r['code']);
        // subcategory_id may appear in groupResults() null check — only verify constructor
        $this->assertStringNotContainsString('public int $subcategory_id', $r['code']);
        $this->assertStringNotContainsString('public string $subcategory_name', $r['code']);

        // Item has subcategory columns, NOT primary columns
        $this->assertStringContainsString('public int $subcategory_id', $r['itemCode']);
        $this->assertStringContainsString('public string $subcategory_name', $r['itemCode']);
        $this->assertStringNotContainsString('public int $id', $r['itemCode']);
    }

    public function test_self_join_items_property_named_items(): void
    {
        $r = $this->grouped(
            "-- @name ListCategories\n-- @class RefundsCategories\n" .
            "-- @group_by refunds_categories.id\n-- @returns :grouped\n" .
            "SELECT refunds_categories.id, refunds_categories.name,\n" .
            "       refunds_subcategories.id as subcategory_id,\n" .
            "       refunds_subcategories.name as subcategory_name\n" .
            "FROM refunds_categories\n" .
            "LEFT JOIN refunds_categories as refunds_subcategories\n" .
            "    ON refunds_subcategories.parent_id = refunds_categories.id\n" .
            "WHERE refunds_categories.parent_id IS NULL;"
        );

        // For self-joins, the items property should be named 'items' not the table name
        $this->assertStringContainsString('public array $items', $r['code']);
        $this->assertStringNotContainsString('public array $refunds_categories', $r['code']);
    }

    public function test_self_join_groupresults_skips_null_left_join_rows(): void
    {
        $r = $this->grouped(
            "-- @name ListCategories\n-- @class RefundsCategories\n" .
            "-- @group_by refunds_categories.id\n-- @returns :grouped\n" .
            "SELECT refunds_categories.id, refunds_categories.name,\n" .
            "       refunds_subcategories.id as subcategory_id,\n" .
            "       refunds_subcategories.name as subcategory_name\n" .
            "FROM refunds_categories\n" .
            "LEFT JOIN refunds_categories as refunds_subcategories\n" .
            "    ON refunds_subcategories.parent_id = refunds_categories.id\n" .
            "WHERE refunds_categories.parent_id IS NULL;"
        );

        // groupResults must check for NULL before adding items (LEFT JOIN case)
        $this->assertStringContainsString('subcategory_id', $r['code']);
        $this->assertStringContainsString('!== null', $r['code']);
    }

    public function test_self_join_users_with_manager(): void
    {
        $r = $this->grouped(
            "-- @name ListUsersWithReports\n-- @class Users\n" .
            "-- @group_by users.id\n-- @returns :grouped\n" .
            "SELECT users.id, users.name,\n" .
            "       reports.id as report_id,\n" .
            "       reports.name as report_name\n" .
            "FROM users\n" .
            "LEFT JOIN users as reports ON reports.manager_id = users.id;"
        );

        $this->assertSame('ListUsersWithReportsItem', $r['itemClass']);
        $this->assertStringContainsString('public int $report_id', $r['itemCode']);
        $this->assertStringContainsString('public string $report_name', $r['itemCode']);
        $this->assertStringContainsString('public int $id', $r['code']);
        $this->assertStringContainsString('public array $items', $r['code']);
    }

    // =========================================================================
    // Regular JOIN (different tables) — existing behavior unchanged
    // =========================================================================

    public function test_regular_join_still_works(): void
    {
        $schema2 = <<<SQL
            CREATE TABLE orders (id INT AUTO_INCREMENT PRIMARY KEY, user_id INT NOT NULL, total DECIMAL(10,2) NOT NULL);
            CREATE TABLE order_items (id INT AUTO_INCREMENT PRIMARY KEY, order_id INT NOT NULL, name VARCHAR(100) NOT NULL, qty INT NOT NULL);
        SQL;
        $catalog2 = new SchemaCatalog((new SchemaParser())->parse($schema2));
        $mapper2  = new MySQLTypeMapper();
        $parser2  = new QueryParser('english');
        $pr2 = new ParamResolver($catalog2, $mapper2);
        $er2 = new ExpressionTypeResolver($catalog2, $mapper2);
        $cr2 = new ColumnResolver($catalog2, $mapper2, $pr2, $er2);
        $analyzer2 = new QueryAnalyzer($pr2, $cr2, $parser2, new SqlRewriter(), $catalog2);
        $dtoGen2   = new ResultDtoGenerator('App\\DTOs', $mapper2, $catalog2);

        $q = $analyzer2->analyze($parser2->parse(
            "-- @name GetOrderWithItems\n-- @class Orders\n" .
            "-- @group_by orders.id\n-- @returns :grouped\n" .
            "SELECT orders.id, orders.total,\n" .
            "       order_items.id as item_id, order_items.name as item_name, order_items.qty\n" .
            "FROM orders\n" .
            "LEFT JOIN order_items ON order_items.order_id = orders.id\n" .
            "WHERE orders.id = :id;"
        ));
        $r = $dtoGen2->generateGrouped($q[0]);

        // Primary table columns in Row
        $this->assertStringContainsString('public int $id', $r['code']);
        $this->assertStringContainsString('public float $total', $r['code']);

        // JOIN-side columns in Item
        $this->assertStringContainsString('public int $item_id', $r['itemCode']);
        $this->assertStringContainsString('public string $item_name', $r['itemCode']);
        $this->assertStringContainsString('public int $qty', $r['itemCode']);
    }
}
