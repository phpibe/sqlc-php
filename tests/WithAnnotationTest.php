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
 * Tests for the @with annotation (v2.18.0).
 *
 * @with is the unified annotation for query capability extensions:
 *
 *   -- @with criteria   → generate typed Criteria class + $criteria param (replaces @searchable)
 *   -- @with count      → generate {name}Count(): int companion method  (replaces @counted)
 *   -- @with exists     → generate {name}Exists(): bool companion method (new)
 *   -- @with criteria, count, exists   → all three at once
 *
 * @searchable and @counted are deprecated — they still work but emit stderr warnings.
 */
class WithAnnotationTest extends TestCase
{
    private SchemaCatalog   $catalog;
    private MySQLTypeMapper $mapper;
    private QueryParser     $parser;
    private QueryAnalyzer   $analyzer;
    private QueryGenerator  $queryGen;

    protected function setUp(): void
    {
        $schema = <<<SQL
            CREATE TABLE orders (
                id         INT          AUTO_INCREMENT PRIMARY KEY,
                user_id    INT          NOT NULL,
                status     VARCHAR(20)  NOT NULL DEFAULT 'pending',
                total      DECIMAL(10,2) NOT NULL,
                created_at DATETIME     NOT NULL
            );
            CREATE TABLE users (
                id    INT         AUTO_INCREMENT PRIMARY KEY,
                email VARCHAR(100) NOT NULL,
                name  VARCHAR(100) NOT NULL
            );
        SQL;

        $this->catalog  = new SchemaCatalog((new SchemaParser())->parse($schema));
        $this->mapper   = new MySQLTypeMapper();
        $this->parser   = new QueryParser();
        $pr             = new ParamResolver($this->catalog, $this->mapper);
        $er             = new ExpressionTypeResolver($this->catalog, $this->mapper);
        $cr             = new ColumnResolver($this->catalog, $this->mapper, $pr, $er);
        $this->analyzer = new QueryAnalyzer($pr, $cr, $this->parser, new SqlRewriter(), $this->catalog);
        $dtoGen         = new ResultDtoGenerator('App\\DTOs', $this->mapper, $this->catalog);
        $this->queryGen = new QueryGenerator($this->catalog, $this->mapper, $dtoGen, 'App\\Queries');
    }

    private function analyze(string $sql): array
    {
        return $this->analyzer->analyze($this->parser->parse($sql));
    }

    private function code(array $queries): string
    {
        $r = $this->queryGen->generate($queries);
        foreach ($r as $item) {
            if (str_ends_with($item['className'], 'Query')) return $item['code'];
        }
        return array_values($r)[0]['code'];
    }

    // =========================================================================
    // Parser — @with sets the right flags
    // =========================================================================

    public function test_with_criteria_sets_searchable_flag(): void
    {
        $q = $this->analyze(
            "-- @name ListOrders\n-- @class Orders\n-- @returns :many\n-- @with criteria\n" .
            "SELECT orders.id, orders.status FROM orders;"
        );
        $this->assertTrue($q[0]->searchable);
        $this->assertFalse($q[0]->counted);
        $this->assertFalse($q[0]->exists);
    }

    public function test_with_count_sets_counted_flag(): void
    {
        $q = $this->analyze(
            "-- @name ListOrders\n-- @class Orders\n-- @returns :many-paginated\n-- @with count\n" .
            "SELECT orders.id FROM orders;"
        );
        $this->assertTrue($q[0]->counted);
        $this->assertFalse($q[0]->searchable);
        $this->assertFalse($q[0]->exists);
    }

    public function test_with_exists_sets_exists_flag(): void
    {
        $q = $this->analyze(
            "-- @name ListOrders\n-- @class Orders\n-- @returns :many\n-- @with exists\n" .
            "SELECT orders.id FROM orders;"
        );
        $this->assertTrue($q[0]->exists);
        $this->assertFalse($q[0]->searchable);
        $this->assertFalse($q[0]->counted);
    }

    public function test_with_all_three_sets_all_flags(): void
    {
        $q = $this->analyze(
            "-- @name ListOrders\n-- @class Orders\n-- @returns :many-paginated\n-- @with criteria, count, exists\n" .
            "SELECT orders.id, orders.status, orders.total FROM orders;"
        );
        $this->assertTrue($q[0]->searchable);
        $this->assertTrue($q[0]->counted);
        $this->assertTrue($q[0]->exists);
    }

    public function test_with_order_does_not_matter(): void
    {
        $q = $this->analyze(
            "-- @name ListOrders\n-- @class Orders\n-- @returns :many-paginated\n-- @with count, exists, criteria\n" .
            "SELECT orders.id, orders.status FROM orders;"
        );
        $this->assertTrue($q[0]->searchable);
        $this->assertTrue($q[0]->counted);
        $this->assertTrue($q[0]->exists);
    }

    public function test_with_modifiers_case_insensitive(): void
    {
        $q = $this->analyze(
            "-- @name ListOrders\n-- @class Orders\n-- @returns :many\n-- @with CRITERIA, EXISTS\n" .
            "SELECT orders.id FROM orders;"
        );
        $this->assertTrue($q[0]->searchable);
        $this->assertTrue($q[0]->exists);
    }

    // =========================================================================
    // Generated code — @with criteria
    // =========================================================================

    public function test_with_criteria_generates_criteria_param(): void
    {
        $q    = $this->analyze(
            "-- @name ListOrders\n-- @class Orders\n-- @returns :many\n-- @with criteria\n" .
            "SELECT orders.id, orders.status, orders.total FROM orders;"
        );
        $code = $this->code($q);
        $this->assertStringContainsString('ListOrdersCriteria', $code);
        $this->assertStringContainsString('?ListOrdersCriteria $criteria = null', $code);
    }

    // =========================================================================
    // Generated code — @with count
    // =========================================================================

    public function test_with_count_generates_count_method_on_many_paginated(): void
    {
        $q    = $this->analyze(
            "-- @name ListOrders\n-- @class Orders\n-- @returns :many-paginated\n-- @with count\n" .
            "SELECT orders.id, orders.status FROM orders WHERE user_id = :userId;"
        );
        $code = $this->code($q);
        $this->assertStringContainsString('function listOrdersCount(', $code);
        $this->assertStringContainsString(': int', $code);
        $this->assertStringContainsString('COUNT(*)', $code);
    }

    public function test_with_count_on_cursor_generates_count_method(): void
    {
        $q    = $this->analyze(
            "-- @name ListOrders\n-- @class Orders\n-- @returns :cursor\n" .
            "-- @with count\n-- @cursor created_at DESC, id DESC\n" .
            "SELECT orders.id, orders.total, orders.created_at FROM orders ORDER BY orders.created_at DESC, orders.id DESC;"
        );
        $code = $this->code($q);
        $this->assertStringContainsString('function listOrdersCount(', $code);
    }

    public function test_with_count_on_many_throws(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/@with count.*:many-paginated/');
        $this->analyze(
            "-- @name ListOrders\n-- @class Orders\n-- @returns :many\n-- @with count\n" .
            "SELECT orders.id FROM orders;"
        );
    }

    // =========================================================================
    // Generated code — @with exists
    // =========================================================================

    public function test_with_exists_generates_exists_method_on_many(): void
    {
        $q    = $this->analyze(
            "-- @name ListOrders\n-- @class Orders\n-- @returns :many\n-- @with exists\n" .
            "SELECT orders.id, orders.status FROM orders WHERE user_id = :userId;"
        );
        $code = $this->code($q);
        $this->assertStringContainsString('function listOrdersExists(', $code);
        $this->assertStringContainsString(': bool', $code);
    }

    public function test_with_exists_uses_select_exists_subquery(): void
    {
        $q    = $this->analyze(
            "-- @name ListOrders\n-- @class Orders\n-- @returns :many\n-- @with exists\n" .
            "SELECT orders.id FROM orders WHERE user_id = :userId;"
        );
        $code = $this->code($q);
        $this->assertStringContainsString('SELECT EXISTS', $code);
        $this->assertStringContainsString('_exists_subquery', $code);
        $this->assertStringContainsString("return (bool)", $code);
    }

    public function test_with_exists_on_many_paginated(): void
    {
        $q    = $this->analyze(
            "-- @name ListOrders\n-- @class Orders\n-- @returns :many-paginated\n-- @with exists\n" .
            "SELECT orders.id FROM orders WHERE user_id = :userId;"
        );
        $code = $this->code($q);
        $this->assertStringContainsString('function listOrdersExists(', $code);
    }

    public function test_with_exists_on_cursor(): void
    {
        $q    = $this->analyze(
            "-- @name ListOrders\n-- @class Orders\n-- @returns :cursor\n-- @with exists\n" .
            "-- @cursor created_at DESC, id DESC\n" .
            "SELECT orders.id, orders.total, orders.created_at FROM orders ORDER BY orders.created_at DESC, orders.id DESC;"
        );
        $code = $this->code($q);
        $this->assertStringContainsString('function listOrdersExists(', $code);
    }

    public function test_with_exists_on_invalid_return_type_throws(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/@with exists.*:many/');
        $this->analyze(
            "-- @name GetOrder\n-- @class Orders\n-- @returns :one\n-- @with exists\n" .
            "SELECT orders.id FROM orders WHERE id = :id;"
        );
    }

    // =========================================================================
    // Generated code — @with criteria, count, exists combined
    // =========================================================================

    public function test_with_all_three_generates_all_methods(): void
    {
        $q    = $this->analyze(
            "-- @name ListOrders\n-- @class Orders\n-- @returns :many-paginated\n-- @with criteria, count, exists\n" .
            "SELECT orders.id, orders.status, orders.total, orders.created_at FROM orders;"
        );
        $code = $this->code($q);

        $this->assertStringContainsString('ListOrdersCriteria', $code);
        $this->assertStringContainsString('function listOrdersCount(', $code);
        $this->assertStringContainsString('function listOrdersExists(', $code);
    }

    public function test_with_criteria_exists_generates_exists_with_criteria_param(): void
    {
        $q    = $this->analyze(
            "-- @name ListOrders\n-- @class Orders\n-- @returns :many\n-- @with criteria, exists\n" .
            "SELECT orders.id, orders.status, orders.total FROM orders;"
        );
        $code = $this->code($q);

        // Exists method should accept criteria param when criteria is also active
        $this->assertStringContainsString('function listOrdersExists(', $code);
        $existsStart = strpos($code, 'function listOrdersExists(');
        $existsEnd   = strpos($code, '}', $existsStart);
        $existsBody  = substr($code, $existsStart, $existsEnd - $existsStart);
        $this->assertStringContainsString('ListOrdersCriteria', $existsBody);
    }

    // =========================================================================
    // Regression: multiple @with criteria queries in same @class must not collide
    // =========================================================================

    public function test_two_criteria_queries_in_same_class_produce_distinct_files(): void
    {
        // This is the exact bug: two @with criteria queries in the same @class
        // used to both generate 'ProfileCriteria.php', the second overwriting the first.
        // Now each uses @name as the base: ListProfilesCriteria, SearchProfilesCriteria.
        $q = $this->analyze(<<<SQL
            -- @name ListProfiles
            -- @class Profile
            -- @returns :many
            -- @with criteria
            SELECT orders.id, orders.status, orders.total FROM orders;

            -- @name SearchProfiles
            -- @class Profile
            -- @returns :many
            -- @with criteria
            SELECT orders.id, orders.status FROM orders WHERE orders.total > :minTotal;
        SQL);

        $r = $this->queryGen->generate($q);

        $criteriaFiles = array_filter($r, fn($f) => str_ends_with($f['className'], 'Criteria'));
        $classNames    = array_column($criteriaFiles, 'className');

        // Two distinct Criteria classes must be generated
        $this->assertCount(2, $criteriaFiles,
            'Two @with criteria queries in the same @class must produce two distinct Criteria files');

        $this->assertContains('ListProfilesCriteria',   $classNames);
        $this->assertContains('SearchProfilesCriteria', $classNames);

        // Neither must be the old group-based name
        $this->assertNotContains('ProfileCriteria', $classNames,
            'Criteria class must be named after @name, not @class');
    }

    public function test_criteria_class_named_after_query_name_not_class(): void
    {
        $q    = $this->analyze(
            "-- @name ListOrders\n-- @class Orders\n-- @returns :many\n-- @with criteria\n" .
            "SELECT orders.id, orders.status, orders.total FROM orders;"
        );
        $r    = $this->queryGen->generate($q);
        $code = $this->code($q);

        // Method param must reference ListOrdersCriteria (from @name), not bare OrdersCriteria (from @class)
        $this->assertStringContainsString('?ListOrdersCriteria $criteria', $code);
        // Must NOT have the old group-based name without the @name prefix
        $this->assertStringNotContainsString('?OrdersCriteria $criteria', $code);

        // The generated file must be named after @name
        $keys = array_keys($r);
        $this->assertContains('ListOrdersCriteria.php', $keys);
        $this->assertNotContains('OrdersCriteria.php', $keys);
    }

    public function test_with_all_three_modifiers_use_name_based_criteria(): void
    {
        $q    = $this->analyze(
            "-- @name ListOrders\n-- @class Orders\n-- @returns :many-paginated\n-- @with criteria, count, exists\n" .
            "SELECT orders.id, orders.status, orders.total, orders.created_at FROM orders;"
        );
        $code = $this->code($q);

        // All three companion methods must reference ListOrdersCriteria
        $this->assertStringContainsString('?ListOrdersCriteria $criteria', $code);
        $this->assertStringNotContainsString('?OrdersCriteria $criteria', $code);
    }
    // =========================================================================

    public function test_deprecated_searchable_still_works(): void
    {
        $q = $this->analyze(
            "-- @name ListOrders\n-- @class Orders\n-- @returns :many\n-- @searchable\n" .
            "SELECT orders.id, orders.status FROM orders;"
        );
        $this->assertTrue($q[0]->searchable);
    }

    public function test_deprecated_counted_still_works(): void
    {
        $q = $this->analyze(
            "-- @name ListOrders\n-- @class Orders\n-- @returns :many-paginated\n-- @counted\n" .
            "SELECT orders.id FROM orders;"
        );
        $this->assertTrue($q[0]->counted);
    }

    public function test_deprecated_searchable_and_counted_combined_still_works(): void
    {
        $q = $this->analyze(
            "-- @name ListOrders\n-- @class Orders\n-- @returns :many-paginated\n" .
            "-- @searchable\n-- @counted\n" .
            "SELECT orders.id, orders.status, orders.total FROM orders;"
        );
        $this->assertTrue($q[0]->searchable);
        $this->assertTrue($q[0]->counted);
    }
}
