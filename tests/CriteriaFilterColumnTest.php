<?php

declare(strict_types=1);

namespace SqlcPhp\Tests;

use PHPUnit\Framework\TestCase;
use SqlcPhp\Analyzer\QueryAnalyzer;
use SqlcPhp\Catalog\SchemaCatalog;
use SqlcPhp\Generator\CriteriaGenerator;
use SqlcPhp\Generator\EnumGenerator;
use SqlcPhp\Parser\QueryParser;
use SqlcPhp\Parser\SchemaParser;
use SqlcPhp\Resolver\ColumnResolver;
use SqlcPhp\Resolver\ExpressionTypeResolver;
use SqlcPhp\Resolver\ParamResolver;
use SqlcPhp\Rewriter\SqlRewriter;
use SqlcPhp\TypeMapper\MySQLTypeMapper;

/**
 * Tests for the qualified filter column fix in CriteriaGenerator.
 *
 * When a @searchable query spans multiple tables via JOIN, column names in the
 * generated Filter calls must be qualified as `table.column` to avoid MySQL
 * "Column is ambiguous" errors.
 *
 * The fix: CriteriaGenerator uses `$col->tableName . '.' . $col->columnName`
 * as the Filter column reference whenever both are available, falling back
 * to the alias only for expression columns (aggregates, computed values).
 *
 * Method names and COLUMN_ constants still use the alias — only the Filter
 * string changes, since that's what reaches MySQL's WHERE clause.
 */
class CriteriaFilterColumnTest extends TestCase
{
    private SchemaCatalog   $catalog;
    private MySQLTypeMapper $mapper;
    private QueryParser     $parser;
    private QueryAnalyzer   $analyzer;
    private CriteriaGenerator $gen;

    protected function setUp(): void
    {
        $schema = <<<SQL
            CREATE TABLE reserves (
                id         INT          AUTO_INCREMENT PRIMARY KEY,
                reserve_id VARCHAR(20)  NOT NULL,
                status     VARCHAR(20)  NOT NULL
            );
            CREATE TABLE products (
                id         INT          AUTO_INCREMENT PRIMARY KEY,
                reserve_id VARCHAR(20)  NOT NULL,
                name       VARCHAR(100) NOT NULL,
                price      DECIMAL(10,2) NOT NULL
            );
            CREATE TABLE payments (
                id         INT          AUTO_INCREMENT PRIMARY KEY,
                reserve_id VARCHAR(20)  NOT NULL,
                amount     DECIMAL(10,2) NOT NULL
            );
            CREATE TABLE users (
                id    INT          AUTO_INCREMENT PRIMARY KEY,
                email VARCHAR(100) NOT NULL,
                name  VARCHAR(100) NULL
            );
        SQL;

        $this->catalog  = new SchemaCatalog((new SchemaParser())->parse($schema));
        $this->mapper   = new MySQLTypeMapper([], new EnumGenerator('App'));
        $this->parser   = new QueryParser();
        $pr             = new ParamResolver($this->catalog, $this->mapper);
        $er             = new ExpressionTypeResolver($this->catalog, $this->mapper);
        $cr             = new ColumnResolver($this->catalog, $this->mapper, $pr, $er);
        $this->analyzer = new QueryAnalyzer($pr, $cr, $this->parser, new SqlRewriter(), $this->catalog);
        $this->gen      = new CriteriaGenerator('App\\Criterias', $this->mapper);
    }

    private function criteria(string $sql): string
    {
        $queries = $this->analyzer->analyze($this->parser->parse($sql));
        $r       = $this->gen->generate($queries[0], $queries[0]->resultColumns);
        return $r['code'];
    }

    // =========================================================================
    // Single table — columns stay unqualified (backward compatibility)
    // =========================================================================

    public function test_single_table_filter_uses_bare_column_name(): void
    {
        $code = $this->criteria(<<<SQL
            -- @name ListUsers
            -- @class User
            -- @returns :many
            -- @searchable
            SELECT users.id, users.email FROM users;
        SQL);

        // Single table: no need to qualify — bare column name
        $this->assertStringContainsString("Filter::eq('users.id'", $code);
        $this->assertStringContainsString("Filter::eq('users.email'", $code);
    }

    public function test_single_table_star_filter_uses_qualified_column(): void
    {
        $code = $this->criteria(<<<SQL
            -- @name ListUsers
            -- @class User
            -- @returns :many
            -- @searchable
            SELECT * FROM users;
        SQL);

        $this->assertStringContainsString("Filter::eq('users.id'", $code);
        $this->assertStringContainsString("Filter::eq('users.email'", $code);
    }

    // =========================================================================
    // Multi-table JOIN — columns must be qualified to avoid ambiguity
    // =========================================================================

    public function test_join_shared_column_generates_qualified_filter(): void
    {
        $code = $this->criteria(<<<SQL
            -- @name ListReserves
            -- @class Reserves
            -- @returns :many
            -- @searchable
            SELECT reserves.id, reserves.reserve_id, reserves.status,
                   products.reserve_id AS product_reserve_id,
                   products.name       AS product_name
            FROM reserves
            INNER JOIN products ON products.reserve_id = reserves.reserve_id;
        SQL);

        // reserves.reserve_id → qualified
        $this->assertStringContainsString("Filter::eq('reserves.reserve_id'", $code);
        // products.reserve_id (aliased as product_reserve_id) → qualified
        $this->assertStringContainsString("Filter::eq('products.reserve_id'", $code);
    }

    public function test_join_three_tables_all_columns_qualified(): void
    {
        $code = $this->criteria(<<<SQL
            -- @name ListReservesFull
            -- @class Reserves
            -- @returns :many
            -- @searchable
            SELECT reserves.id,
                   reserves.reserve_id,
                   reserves.status,
                   products.reserve_id AS product_reserve_id,
                   products.name       AS product_name,
                   payments.reserve_id AS payment_reserve_id,
                   payments.amount
            FROM reserves
            INNER JOIN products ON products.reserve_id = reserves.reserve_id
            INNER JOIN payments ON payments.reserve_id = reserves.reserve_id;
        SQL);

        $this->assertStringContainsString("Filter::eq('reserves.reserve_id'",  $code);
        $this->assertStringContainsString("Filter::eq('products.reserve_id'",  $code);
        $this->assertStringContainsString("Filter::eq('payments.reserve_id'",  $code);
        $this->assertStringContainsString("Filter::eq('payments.amount'",       $code);
        $this->assertStringContainsString("Filter::eq('products.name'",         $code);
    }

    public function test_join_non_ambiguous_columns_are_still_qualified(): void
    {
        // Even columns that exist only in one table get qualified — safer and consistent
        $code = $this->criteria(<<<SQL
            -- @name ListReserves
            -- @class Reserves
            -- @returns :many
            -- @searchable
            SELECT reserves.id, reserves.status,
                   products.name AS product_name,
                   payments.amount
            FROM reserves
            INNER JOIN products ON products.id = reserves.id
            INNER JOIN payments ON payments.id = reserves.id;
        SQL);

        $this->assertStringContainsString("Filter::eq('reserves.id'",      $code);
        $this->assertStringContainsString("Filter::eq('reserves.status'",  $code);
        $this->assertStringContainsString("Filter::eq('products.name'",    $code);
        $this->assertStringContainsString("Filter::eq('payments.amount'",  $code);
    }

    // =========================================================================
    // Method names still use alias — only Filter string changes
    // =========================================================================

    public function test_method_name_still_uses_alias(): void
    {
        $code = $this->criteria(<<<SQL
            -- @name ListReserves
            -- @class Reserves
            -- @returns :many
            -- @searchable
            SELECT reserves.reserve_id,
                   products.reserve_id AS product_reserve_id
            FROM reserves
            INNER JOIN products ON products.reserve_id = reserves.reserve_id;
        SQL);

        // Method names derived from aliases
        $this->assertStringContainsString('function whereReserveIdEq(', $code);
        $this->assertStringContainsString('function whereProductReserveIdEq(', $code);

        // But Filter strings are qualified
        $this->assertStringContainsString("Filter::eq('reserves.reserve_id'",  $code);
        $this->assertStringContainsString("Filter::eq('products.reserve_id'",  $code);
    }

    public function test_order_by_method_still_uses_alias(): void
    {
        $code = $this->criteria(<<<SQL
            -- @name ListReserves
            -- @class Reserves
            -- @returns :many
            -- @searchable
            SELECT reserves.reserve_id,
                   products.reserve_id AS product_reserve_id
            FROM reserves
            INNER JOIN products ON products.reserve_id = reserves.reserve_id;
        SQL);

        // orderBy uses alias — MySQL ORDER BY accepts SELECT aliases
        $this->assertStringContainsString("orderBy('reserve_id'",         $code);
        $this->assertStringContainsString("orderBy('product_reserve_id'", $code);
    }

    public function test_column_constant_still_uses_alias(): void
    {
        $code = $this->criteria(<<<SQL
            -- @name ListReserves
            -- @class Reserves
            -- @returns :many
            -- @searchable
            SELECT reserves.reserve_id,
                   products.reserve_id AS product_reserve_id
            FROM reserves
            INNER JOIN products ON products.reserve_id = reserves.reserve_id;
        SQL);

        $this->assertStringContainsString("COLUMN_RESERVE_ID = 'reserve_id'",         $code);
        $this->assertStringContainsString("COLUMN_PRODUCT_RESERVE_ID = 'product_reserve_id'", $code);
    }

    // =========================================================================
    // Expression / aggregate columns — no table, fall back to alias
    // =========================================================================

    public function test_expression_column_falls_back_to_alias(): void
    {
        $code = $this->criteria(<<<SQL
            -- @name ListReserves
            -- @class Reserves
            -- @returns :many
            -- @searchable
            SELECT reserves.id,
                   COUNT(payments.id) AS payment_count
            FROM reserves
            LEFT JOIN payments ON payments.reserve_id = reserves.reserve_id
            GROUP BY reserves.id;
        SQL);

        // COUNT(...) has no tableName — must use alias
        $this->assertStringContainsString("Filter::eq('payment_count'", $code);
        // reserves.id is a real table column — must be qualified
        $this->assertStringContainsString("Filter::eq('reserves.id'", $code);
    }

    // =========================================================================
    // All filter method types use the qualified column
    // =========================================================================

    public function test_all_string_filter_methods_use_qualified_column(): void
    {
        $code = $this->criteria(<<<SQL
            -- @name ListReserves
            -- @class Reserves
            -- @returns :many
            -- @searchable
            SELECT reserves.reserve_id,
                   products.name AS product_name
            FROM reserves
            INNER JOIN products ON products.reserve_id = reserves.reserve_id;
        SQL);

        // reserves.reserve_id string methods
        foreach (['eq', 'neq', 'like', 'starts', 'ends', 'in', 'notIn'] as $method) {
            $this->assertStringContainsString("Filter::{$method}('reserves.reserve_id'", $code);
        }
    }

    public function test_in_and_not_in_use_qualified_column(): void
    {
        $code = $this->criteria(<<<SQL
            -- @name ListReserves
            -- @class Reserves
            -- @returns :many
            -- @searchable
            SELECT reserves.id,
                   products.id AS product_id
            FROM reserves
            INNER JOIN products ON products.reserve_id = reserves.reserve_id;
        SQL);

        $this->assertStringContainsString("Filter::in('reserves.id'",     $code);
        $this->assertStringContainsString("Filter::notIn('reserves.id'",  $code);
        $this->assertStringContainsString("Filter::in('products.id'",     $code);
        $this->assertStringContainsString("Filter::notIn('products.id'",  $code);
    }

    public function test_between_date_uses_qualified_column(): void
    {
        $schema2 = <<<SQL
            CREATE TABLE orders (
                id         INT      AUTO_INCREMENT PRIMARY KEY,
                created_at DATETIME NOT NULL
            );
            CREATE TABLE order_items (
                id         INT      AUTO_INCREMENT PRIMARY KEY,
                created_at DATETIME NOT NULL
            );
        SQL;
        $catalog2  = new SchemaCatalog((new SchemaParser())->parse($schema2));
        $mapper2   = new MySQLTypeMapper([], new EnumGenerator('App'));
        $pr2       = new ParamResolver($catalog2, $mapper2);
        $er2       = new ExpressionTypeResolver($catalog2, $mapper2);
        $cr2       = new ColumnResolver($catalog2, $mapper2, $pr2, $er2);
        $analyzer2 = new QueryAnalyzer($pr2, $cr2, $this->parser, new SqlRewriter(), $catalog2);
        $gen2      = new CriteriaGenerator('App\\Criterias', $mapper2);

        $queries = $analyzer2->analyze($this->parser->parse(<<<SQL
            -- @name ListOrders
            -- @class Orders
            -- @returns :many
            -- @searchable
            SELECT orders.id, orders.created_at,
                   order_items.created_at AS item_created_at
            FROM orders
            INNER JOIN order_items ON order_items.id = orders.id;
        SQL));

        $code = $gen2->generate($queries[0], $queries[0]->resultColumns)['code'];

        $this->assertStringContainsString("Filter::between('orders.created_at'",      $code);
        $this->assertStringContainsString("Filter::between('order_items.created_at'", $code);
    }

    // =========================================================================
    // Allowed columns list and COLUMN_ constants — unaffected (use alias)
    // =========================================================================

    public function test_allowed_columns_list_uses_alias_not_qualified(): void
    {
        $code = $this->criteria(<<<SQL
            -- @name ListReserves
            -- @class Reserves
            -- @returns :many
            -- @searchable
            SELECT reserves.reserve_id,
                   products.reserve_id AS product_reserve_id
            FROM reserves
            INNER JOIN products ON products.reserve_id = reserves.reserve_id;
        SQL);

        // Extract the allowedColumns array line specifically
        preg_match('/protected array \$allowedColumns = \[([^\]]+)\]/', $code, $m);
        $this->assertNotEmpty($m, 'allowedColumns array not found');
        $allowedLine = $m[1];

        // allowedColumns for ORDER BY validation must use aliases (not qualified)
        $this->assertStringContainsString("'reserve_id'",         $allowedLine);
        $this->assertStringContainsString("'product_reserve_id'", $allowedLine);
        $this->assertStringNotContainsString('reserves.',  $allowedLine);
        $this->assertStringNotContainsString('products.',  $allowedLine);
    }
}
