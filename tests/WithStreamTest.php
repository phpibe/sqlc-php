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
 * Tests for @with stream (v2.19.8).
 *
 * @with stream generates a stream{Name}(): \Generator companion method
 * that yields rows one at a time using PDO fetch, avoiding full in-memory arrays.
 *
 * Compatible with:
 *   @with stream, criteria    → streamName accepts $criteria
 *   @with stream, count       → listNameCount() companion
 *   @with stream, exists      → listNameExists() companion
 *   @with stream, criteria, count, exists  → all four
 */
class WithStreamTest extends TestCase
{
    private SchemaCatalog  $catalog;
    private QueryAnalyzer  $analyzer;
    private QueryGenerator $queryGen;
    private QueryParser    $parser;

    protected function setUp(): void
    {
        $schema = <<<SQL
            CREATE TABLE orders (
                id         INT           AUTO_INCREMENT PRIMARY KEY,
                user_id    INT           NOT NULL,
                status     VARCHAR(20)   NOT NULL,
                total      DECIMAL(10,2) NOT NULL,
                created_at DATETIME      NOT NULL
            );
        SQL;

        $this->catalog  = new SchemaCatalog((new SchemaParser())->parse($schema));
        $mapper         = new MySQLTypeMapper();
        $this->parser   = new QueryParser();
        $pr             = new ParamResolver($this->catalog, $mapper);
        $er             = new ExpressionTypeResolver($this->catalog, $mapper);
        $cr             = new ColumnResolver($this->catalog, $mapper, $pr, $er);
        $this->analyzer = new QueryAnalyzer($pr, $cr, $this->parser, new SqlRewriter(), $this->catalog);
        $dtoGen         = new ResultDtoGenerator('App\\DTOs', $mapper, $this->catalog);
        $this->queryGen = new QueryGenerator($this->catalog, $mapper, $dtoGen, 'App\\Queries');
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
    // Parser
    // =========================================================================

    public function test_with_stream_sets_stream_flag(): void
    {
        $q = $this->analyze(
            "-- @name ListOrders\n-- @class Orders\n-- @returns :many\n-- @with stream\n" .
            "SELECT orders.id, orders.status FROM orders WHERE user_id = :userId;"
        );
        $this->assertTrue($q[0]->stream);
    }

    public function test_with_stream_combined_with_criteria(): void
    {
        $q = $this->analyze(
            "-- @name ListOrders\n-- @class Orders\n-- @returns :many\n-- @with stream, criteria\n" .
            "SELECT orders.id, orders.status, orders.total FROM orders WHERE user_id = :userId;"
        );
        $this->assertTrue($q[0]->stream);
        $this->assertTrue($q[0]->searchable);
    }

    public function test_with_stream_combined_with_count(): void
    {
        $q = $this->analyze(
            "-- @name ListOrders\n-- @class Orders\n-- @returns :many\n-- @with stream, count\n" .
            "SELECT orders.id FROM orders WHERE user_id = :userId;"
        );
        $this->assertTrue($q[0]->stream);
        $this->assertTrue($q[0]->counted);
    }

    public function test_with_stream_on_cursor_throws(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/@with stream.*:many/');

        $this->analyze(
            "-- @name ListOrders\n-- @class Orders\n-- @returns :cursor\n" .
            "-- @with stream\n-- @cursor created_at DESC, id DESC\n" .
            "SELECT orders.id, orders.total, orders.created_at FROM orders\n" .
            "ORDER BY orders.created_at DESC, orders.id DESC;"
        );
    }

    // =========================================================================
    // Generated code — stream method
    // =========================================================================

    public function test_stream_generates_generator_method(): void
    {
        $q    = $this->analyze(
            "-- @name ListOrders\n-- @class Orders\n-- @returns :many\n-- @with stream\n" .
            "SELECT orders.id, orders.status FROM orders WHERE user_id = :userId;"
        );
        $code = $this->code($q);
        $this->assertStringContainsString('function streamListOrders(', $code);
    }

    public function test_stream_method_returns_generator(): void
    {
        $q    = $this->analyze(
            "-- @name ListOrders\n-- @class Orders\n-- @returns :many\n-- @with stream\n" .
            "SELECT orders.id, orders.status FROM orders WHERE user_id = :userId;"
        );
        $code = $this->code($q);
        $this->assertStringContainsString('): \\Generator', $code);
    }

    public function test_stream_method_uses_yield_not_fetchAll(): void
    {
        $q    = $this->analyze(
            "-- @name ListOrders\n-- @class Orders\n-- @returns :many\n-- @with stream\n" .
            "SELECT orders.id, orders.status FROM orders WHERE user_id = :userId;"
        );
        $code  = $this->code($q);
        $start = strpos($code, 'function streamListOrders');
        $end   = strpos($code, "\n    }", $start);
        $body  = substr($code, $start, $end - $start);

        $this->assertStringContainsString('yield',       $body);
        $this->assertStringContainsString('fetch(PDO::FETCH_ASSOC)', $body);
        $this->assertStringNotContainsString('fetchAll', $body);
    }

    public function test_stream_preserves_main_method(): void
    {
        $q    = $this->analyze(
            "-- @name ListOrders\n-- @class Orders\n-- @returns :many\n-- @with stream\n" .
            "SELECT orders.id, orders.status FROM orders WHERE user_id = :userId;"
        );
        $code = $this->code($q);
        // Both the array method and the stream method must exist
        $this->assertStringContainsString('function listOrders(', $code);
        $this->assertStringContainsString('function streamListOrders(', $code);
    }

    // =========================================================================
    // @with stream + criteria
    // =========================================================================

    public function test_stream_with_criteria_accepts_criteria_param(): void
    {
        $q    = $this->analyze(
            "-- @name ListOrders\n-- @class Orders\n-- @returns :many\n-- @with stream, criteria\n" .
            "SELECT orders.id, orders.status, orders.total FROM orders WHERE user_id = :userId;"
        );
        $code = $this->code($q);

        // Stream method must accept same $criteria as main method
        $start  = strpos($code, 'function streamListOrders');
        $sigEnd = strpos($code, ')', $start);
        $sig    = substr($code, $start, $sigEnd - $start + 1);
        $this->assertStringContainsString('ListOrdersCriteria', $sig);
    }

    public function test_stream_with_criteria_applies_filters(): void
    {
        $q    = $this->analyze(
            "-- @name ListOrders\n-- @class Orders\n-- @returns :many\n-- @with stream, criteria\n" .
            "SELECT orders.id, orders.status, orders.total FROM orders;"
        );
        $code  = $this->code($q);
        $start = strpos($code, 'function streamListOrders');
        $end   = strpos($code, "\n    }", $start);
        $body  = substr($code, $start, $end - $start);

        // Must apply criteria filters to the SQL
        $this->assertStringContainsString('toFilterClause', $body);
        $this->assertStringContainsString('bindAll', $body);
    }

    // =========================================================================
    // @with stream + count
    // =========================================================================

    public function test_stream_with_count_generates_count_method(): void
    {
        $q    = $this->analyze(
            "-- @name ListOrders\n-- @class Orders\n-- @returns :many\n-- @with stream, count\n" .
            "SELECT orders.id, orders.status FROM orders WHERE user_id = :userId;"
        );
        $code = $this->code($q);
        $this->assertStringContainsString('function listOrdersCount(', $code);
        $this->assertStringContainsString(': int', $code);
    }

    // =========================================================================
    // @with stream, criteria, count, exists — all four
    // =========================================================================

    public function test_stream_all_four_generates_all_methods(): void
    {
        $q    = $this->analyze(
            "-- @name ListOrders\n-- @class Orders\n-- @returns :many\n" .
            "-- @with stream, criteria, count, exists\n" .
            "SELECT orders.id, orders.status, orders.total, orders.created_at FROM orders\n" .
            "WHERE user_id = :userId;"
        );
        $code = $this->code($q);

        // All four methods must exist
        $this->assertStringContainsString('function listOrders(',       $code); // main
        $this->assertStringContainsString('function listOrdersCount(',  $code); // count
        $this->assertStringContainsString('function listOrdersExists(', $code); // exists
        $this->assertStringContainsString('function streamListOrders(', $code); // stream
    }

    public function test_stream_all_four_criteria_param_on_all_companions(): void
    {
        $q    = $this->analyze(
            "-- @name ListOrders\n-- @class Orders\n-- @returns :many\n" .
            "-- @with stream, criteria, count, exists\n" .
            "SELECT orders.id, orders.status, orders.total, orders.created_at FROM orders\n" .
            "WHERE user_id = :userId;"
        );
        $code = $this->code($q);

        // All companion methods must accept the criteria param
        $this->assertStringContainsString('ListOrdersCriteria', $code);

        // count and exists and stream all have criteria param
        foreach (['listOrdersCount', 'listOrdersExists', 'streamListOrders'] as $method) {
            $start = strpos($code, "function {$method}(");
            $end   = strpos($code, ')', $start);
            $sig   = substr($code, $start, $end - $start);
            $this->assertStringContainsString('ListOrdersCriteria', $sig,
                "Method {$method} must accept ListOrdersCriteria");
        }
    }
}
