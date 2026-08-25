<?php

declare(strict_types=1);

namespace SqlcPhp\Tests;

use PHPUnit\Framework\TestCase;
use SqlcPhp\Analyzer\QueryAnalyzer;
use SqlcPhp\Catalog\SchemaCatalog;
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
 * Tests for @returns :stream — standalone Generator query.
 *
 * Unlike @with stream (companion to :many), :stream is the primary method.
 * The method name is the query name directly (not stream-prefixed).
 */
class StreamReturnTypeTest extends TestCase
{
    private SchemaCatalog $catalog;
    private QueryAnalyzer $analyzer;
    private ResultDtoGenerator $dtoGen;
    private QueryGenerator $queryGen;
    private QueryGenerator $queryGenWithInterface;
    private QueryParser $parser;

    protected function setUp(): void
    {
        $schema = <<<SQL
            CREATE TABLE orders (
                id         INT           AUTO_INCREMENT PRIMARY KEY,
                status     VARCHAR(20)   NOT NULL,
                total      DECIMAL(10,2) NOT NULL,
                user_id    INT           NOT NULL,
                created_at DATETIME      NOT NULL
            );
            CREATE TABLE products (
                id    INT          AUTO_INCREMENT PRIMARY KEY,
                name  VARCHAR(100) NOT NULL,
                price DECIMAL(10,2) NOT NULL
            );
        SQL;

        $this->catalog  = new SchemaCatalog((new SchemaParser())->parse($schema));
        $mapper         = new MySQLTypeMapper();
        $this->parser   = new QueryParser();
        $pr             = new ParamResolver($this->catalog, $mapper);
        $er             = new ExpressionTypeResolver($this->catalog, $mapper);
        $cr             = new ColumnResolver($this->catalog, $mapper, $pr, $er);
        $this->analyzer = new QueryAnalyzer($pr, $cr, $this->parser, new SqlRewriter(), $this->catalog);
        $this->dtoGen   = new ResultDtoGenerator('App\\DTOs', $mapper, $this->catalog);

        $ifaceGen = new InterfaceGenerator('App\\Contracts');
        $this->queryGen = new QueryGenerator(
            $this->catalog, $mapper, $this->dtoGen, 'App\\Queries'
        );
        $this->queryGenWithInterface = new QueryGenerator(
            $this->catalog, $mapper, $this->dtoGen, 'App\\Queries',
            true, $ifaceGen
        );
    }

    private function analyze(string $sql): array
    {
        return $this->analyzer->analyze($this->parser->parse($sql));
    }

    private function queryCode(string $sql): string
    {
        $q = $this->analyze($sql);
        foreach ($this->queryGen->generate($q) as $item) {
            if (str_ends_with($item['className'], 'Query')) return $item['code'];
        }
        return '';
    }

    private function interfaceCode(string $sql): string
    {
        $q = $this->analyze($sql);
        foreach ($this->queryGenWithInterface->generateInterfaces($q) as $item) {
            return $item['code'];
        }
        return '';
    }

    // =========================================================================
    // Parser
    // =========================================================================

    public function test_stream_return_type_parsed(): void
    {
        $q = $this->analyze(
            "-- @name StreamOrders\n-- @class Orders\n-- @returns :stream\n" .
            "SELECT orders.* FROM orders WHERE status = :status;"
        );
        $this->assertSame(ReturnType::Stream, $q[0]->returns);
    }

    // =========================================================================
    // Generated method
    // =========================================================================

    public function test_stream_method_uses_query_name_not_stream_prefix(): void
    {
        $code = $this->queryCode(
            "-- @name StreamPendingOrders\n-- @class Orders\n-- @returns :stream\n" .
            "SELECT orders.* FROM orders WHERE status = :status;"
        );

        // :stream standalone — method IS streamPendingOrders, not streamStreamPendingOrders
        $this->assertStringContainsString('function streamPendingOrders(', $code);
        $this->assertStringNotContainsString('function streamStreamPendingOrders(', $code);
    }

    public function test_stream_method_returns_generator(): void
    {
        $code = $this->queryCode(
            "-- @name StreamOrders\n-- @class Orders\n-- @returns :stream\n" .
            "SELECT orders.* FROM orders WHERE status = :status;"
        );

        $this->assertStringContainsString('\Generator', $code);
        $this->assertStringContainsString('): \Generator', $code);
    }

    public function test_stream_method_uses_fetch_not_fetchall(): void
    {
        $code = $this->queryCode(
            "-- @name StreamOrders\n-- @class Orders\n-- @returns :stream\n" .
            "SELECT orders.* FROM orders WHERE status = :status;"
        );

        // Uses row-by-row fetch with yield, not fetchAll into array
        $this->assertStringContainsString('yield', $code);
        $this->assertStringContainsString('->fetch(PDO::FETCH_ASSOC)', $code);
        $this->assertStringNotContainsString('->fetchAll(', $code);
    }

    public function test_stream_method_with_params(): void
    {
        $code = $this->queryCode(
            "-- @name StreamOrdersByUser\n-- @class Orders\n-- @returns :stream\n" .
            "SELECT orders.* FROM orders WHERE user_id = :user_id AND status = :status\n" .
            "ORDER BY created_at ASC;"
        );

        $this->assertStringContainsString('int $user_id', $code);
        $this->assertStringContainsString('string $status', $code);
        $this->assertStringContainsString('): \Generator', $code);
    }

    public function test_stream_method_no_params(): void
    {
        $code = $this->queryCode(
            "-- @name StreamAllProducts\n-- @class Products\n-- @returns :stream\n" .
            "SELECT products.* FROM products ORDER BY id ASC;"
        );

        $this->assertStringContainsString('function streamAllProducts(): \Generator', $code);
    }

    public function test_stream_method_has_docblock_with_generator_type(): void
    {
        $code = $this->queryCode(
            "-- @name StreamOrders\n-- @class Orders\n-- @returns :stream\n" .
            "SELECT orders.* FROM orders WHERE status = :status;"
        );

        $this->assertStringContainsString('Generator', $code);
        $this->assertStringContainsString('yield', $code);
        $this->assertStringContainsString('Order', $code); // Model name in generated code
    }

    public function test_stream_method_with_visibility_protected(): void
    {
        $code = $this->queryCode(
            "-- @name StreamOrders\n-- @class Orders\n-- @returns :stream\n" .
            "-- @visibility protected\n" .
            "SELECT orders.* FROM orders WHERE status = :status;"
        );

        $this->assertStringContainsString('protected function streamOrders(', $code);
        $this->assertStringNotContainsString('public function streamOrders(', $code);
    }

    // =========================================================================
    // Interface
    // =========================================================================

    public function test_stream_method_appears_in_interface(): void
    {
        $code = $this->interfaceCode(
            "-- @name StreamPendingOrders\n-- @class Orders\n-- @returns :stream\n" .
            "SELECT orders.* FROM orders WHERE status = :status;"
        );

        $this->assertStringContainsString('streamPendingOrders', $code);
        $this->assertStringContainsString('\Generator', $code);
    }

    public function test_stream_protected_excluded_from_interface(): void
    {
        $code = $this->interfaceCode(
            "-- @name StreamOrders\n-- @class Orders\n-- @returns :stream\n" .
            "-- @visibility protected\n" .
            "SELECT orders.* FROM orders WHERE status = :status;"
        );

        $this->assertStringNotContainsString('streamOrders', $code);
    }

    // =========================================================================
    // No DTO generated
    // =========================================================================

    public function test_stream_does_not_generate_dto(): void
    {
        $q = $this->analyze(
            "-- @name StreamOrders\n-- @class Orders\n-- @returns :stream\n" .
            "SELECT orders.id, orders.total FROM orders WHERE status = :status;"
        );

        $files = $this->queryGen->generate($q);
        $dtoFiles = array_filter($files, fn($f) => str_ends_with($f['className'], 'Row'));

        // :stream uses the Model directly — no separate DTO file
        $this->assertEmpty($dtoFiles);
    }

    // =========================================================================
    // Coexistence with :many and @with stream
    // =========================================================================

    public function test_stream_standalone_and_many_with_stream_in_same_class(): void
    {
        $code = $this->queryCode(
            "-- @name StreamAllOrders\n-- @class Orders\n-- @returns :stream\n" .
            "SELECT orders.* FROM orders ORDER BY id ASC;\n\n" .
            "-- @name ListOrders\n-- @class Orders\n-- @returns :many\n" .
            "-- @with stream\n" .
            "SELECT orders.* FROM orders WHERE status = :status;"
        );

        // :stream standalone → streamAllOrders()
        $this->assertStringContainsString('function streamAllOrders()', $code);
        // :many + @with stream → listOrders() + streamListOrders()
        $this->assertStringContainsString('function listOrders(', $code);
        $this->assertStringContainsString('function streamListOrders(', $code);
    }

    public function test_stream_standalone_uses_fromrow(): void
    {
        $code = $this->queryCode(
            "-- @name StreamOrders\n-- @class Orders\n-- @returns :stream\n" .
            "SELECT orders.* FROM orders WHERE status = :status;"
        );

        $this->assertStringContainsString('::fromRow($row)', $code);
    }
}
