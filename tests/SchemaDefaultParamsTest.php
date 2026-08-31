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
 * Tests for schema DEFAULT value propagation into Params DTOs and method signatures.
 *
 * When a column has a DEFAULT value in the schema and a param references that
 * column in an INSERT/UPDATE (:exec) query, the generated code uses the schema
 * default as the PHP default — so from($request->all()) works without requiring
 * optional fields.
 *
 * Rule: schema defaults ONLY apply to :exec queries. SELECT/WHERE params never
 * get schema defaults because they are filter values, not insert values.
 */
class SchemaDefaultParamsTest extends TestCase
{
    private SchemaCatalog $catalog;
    private QueryAnalyzer $analyzer;
    private QueryParser $parser;
    private MySQLTypeMapper $mapper;
    private ResultDtoGenerator $dtoGen;
    private QueryGenerator $gen;

    protected function setUp(): void
    {
        $schema = <<<SQL
            CREATE TABLE products (
                id          INT           AUTO_INCREMENT PRIMARY KEY,
                name        VARCHAR(100)  NOT NULL,
                price       DECIMAL(10,2) NOT NULL,
                status      VARCHAR(20)   NOT NULL DEFAULT 'active',
                stock       INT           NOT NULL DEFAULT 0,
                featured    TINYINT       NOT NULL DEFAULT 0,
                description TEXT          NULL,
                created_at  DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at  DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP
            );
            CREATE TABLE orders (
                id       INT AUTO_INCREMENT PRIMARY KEY,
                user_id  INT NOT NULL,
                status   VARCHAR(20) NOT NULL DEFAULT 'pending',
                total    DECIMAL(10,2) NOT NULL
            );
        SQL;

        $this->catalog = new SchemaCatalog((new SchemaParser())->parse($schema));
        $this->mapper  = new MySQLTypeMapper();
        $this->parser  = new QueryParser('english');
        $pr = new ParamResolver($this->catalog, $this->mapper);
        $er = new ExpressionTypeResolver($this->catalog, $this->mapper);
        $cr = new ColumnResolver($this->catalog, $this->mapper, $pr, $er);
        $this->analyzer = new QueryAnalyzer($pr, $cr, $this->parser, new SqlRewriter(), $this->catalog);
        $this->dtoGen   = new ResultDtoGenerator('App\\DTOs', $this->mapper, $this->catalog);
        $this->gen      = new QueryGenerator($this->catalog, $this->mapper, $this->dtoGen, 'App\\Q');
    }

    private function analyze(string $sql): array
    {
        return $this->analyzer->analyze($this->parser->parse($sql));
    }

    private function params(string $sql): array
    {
        $q = $this->analyze($sql);
        return $this->dtoGen->generateParams($q[0]);
    }

    private function methodSig(string $sql): string
    {
        $q     = $this->analyze($sql);
        $files = $this->gen->generate($q);
        foreach ($files as $f) {
            if (str_ends_with($f['className'] ?? '', 'Query')) {
                return $f['code'];
            }
        }
        return '';
    }

    // =========================================================================
    // ParamResolver — schemaDefault populated correctly
    // =========================================================================

    public function test_schema_default_populated_for_exec_param(): void
    {
        $q = $this->analyze(
            "-- @name CreateProduct\n-- @class Products\n-- @returns :exec\n" .
            "INSERT INTO products (name, price, status) VALUES (:name, :price, :status);"
        );
        $statusParam = null;
        foreach ($q[0]->params as $p) {
            if ($p->name === 'status') $statusParam = $p;
        }
        $this->assertNotNull($statusParam);
        $this->assertSame('active', $statusParam->schemaDefault);
    }

    public function test_schema_default_sql_function_normalised(): void
    {
        $q = $this->analyze(
            "-- @name CreateProduct\n-- @class Products\n-- @returns :exec\n" .
            "INSERT INTO products (name, price, created_at) VALUES (:name, :price, :created_at);"
        );
        $createdAtParam = null;
        foreach ($q[0]->params as $p) {
            if ($p->name === 'created_at') $createdAtParam = $p;
        }
        $this->assertNotNull($createdAtParam);
        $this->assertSame('__SQL_FUNCTION__', $createdAtParam->schemaDefault);
    }

    public function test_schema_default_null_for_column_without_default(): void
    {
        $q = $this->analyze(
            "-- @name CreateProduct\n-- @class Products\n-- @returns :exec\n" .
            "INSERT INTO products (name, price) VALUES (:name, :price);"
        );
        foreach ($q[0]->params as $p) {
            if ($p->name === 'name') {
                $this->assertNull($p->schemaDefault);
            }
        }
    }

    public function test_schema_default_preserved_through_nullable_annotation(): void
    {
        // @param status ?string should still get schemaDefault from schema
        $q = $this->analyze(
            "-- @name CreateProduct\n-- @class Products\n-- @param status ?string\n-- @returns :exec\n" .
            "INSERT INTO products (name, price, status) VALUES (:name, :price, :status);"
        );
        foreach ($q[0]->params as $p) {
            if ($p->name === 'status') {
                $this->assertSame('active', $p->schemaDefault,
                    '@param ?type annotation should preserve schema DEFAULT');
            }
        }
    }

    // =========================================================================
    // Params DTO — @with params
    // =========================================================================

    public function test_params_dto_string_default(): void
    {
        $rp   = $this->params(
            "-- @name CreateProduct\n-- @class Products\n-- @with params\n-- @returns :exec\n" .
            "INSERT INTO products (name, price, status) VALUES (:name, :price, :status);"
        );
        $this->assertStringContainsString("\$status = 'active'", $rp['code']);
    }

    public function test_params_dto_int_default(): void
    {
        $rp = $this->params(
            "-- @name CreateProduct\n-- @class Products\n-- @with params\n-- @returns :exec\n" .
            "INSERT INTO products (name, price, stock) VALUES (:name, :price, :stock);"
        );
        $this->assertStringContainsString('$stock = 0', $rp['code']);
    }

    public function test_params_dto_sql_function_becomes_nullable(): void
    {
        $rp = $this->params(
            "-- @name CreateProduct\n-- @class Products\n-- @with params\n-- @returns :exec\n" .
            "INSERT INTO products (name, price, created_at) VALUES (:name, :price, :created_at);"
        );
        $this->assertStringContainsString('$created_at = null', $rp['code']);
        // Type should be nullable
        $this->assertStringContainsString('?\DateTimeImmutable $created_at', $rp['code']);
    }

    public function test_params_dto_required_params_stay_required(): void
    {
        $rp = $this->params(
            "-- @name CreateProduct\n-- @class Products\n-- @with params\n-- @returns :exec\n" .
            "INSERT INTO products (name, price) VALUES (:name, :price);"
        );
        // name and price have no DEFAULT — still required
        $this->assertStringContainsString('public string $name,', $rp['code']);
        $this->assertStringContainsString('public float $price,', $rp['code']);
        $this->assertStringNotContainsString('$name =', $rp['code']);
        $this->assertStringNotContainsString('$price =', $rp['code']);
    }

    public function test_params_dto_with_explicit_nullable_annotation_gets_schema_default(): void
    {
        // @param stock ?int with DEFAULT 0 → int $stock = 0 (not ?int = null)
        $rp = $this->params(
            "-- @name CreateProduct\n-- @class Products\n-- @with params\n-- @param stock ?int\n-- @returns :exec\n" .
            "INSERT INTO products (name, price, stock) VALUES (:name, :price, :stock);"
        );
        $this->assertStringContainsString('$stock = 0', $rp['code'],
            'Nullable annotation with schema DEFAULT should use schema value');
    }

    public function test_params_dto_ordering_required_then_defaults_then_nullable(): void
    {
        $rp = $this->params(
            "-- @name CreateProduct\n-- @class Products\n-- @with params\n-- @returns :exec\n" .
            "INSERT INTO products (name, price, status, stock, created_at)\n" .
            "VALUES (:name, :price, :status, :stock, :created_at);"
        );
        $code = $rp['code'];
        // Required params come before defaults
        $namePos      = strpos($code, '$name');
        $pricePos     = strpos($code, '$price');
        $statusPos    = strpos($code, '$status');
        $stockPos     = strpos($code, '$stock');
        $createdAtPos = strpos($code, '$created_at');

        $this->assertLessThan($statusPos, $namePos,  'Required $name before default $status');
        $this->assertLessThan($statusPos, $pricePos, 'Required $price before default $status');
        $this->assertLessThan($createdAtPos, $stockPos, 'String default before SQL function default');
    }

    public function test_params_dto_from_uses_schema_default_as_fallback(): void
    {
        $rp = $this->params(
            "-- @name CreateProduct\n-- @class Products\n-- @with params\n-- @returns :exec\n" .
            "INSERT INTO products (name, price, status, stock)\n" .
            "VALUES (:name, :price, :status, :stock);"
        );
        // from() should use schema defaults when key absent from array
        $this->assertStringContainsString("'active'", $rp['code']);
        $this->assertStringContainsString("?? 0", $rp['code']);
    }

    // =========================================================================
    // Individual method signatures (without @with params)
    // =========================================================================

    public function test_individual_method_string_default(): void
    {
        $code = $this->methodSig(
            "-- @name CreateProduct\n-- @class Products\n-- @returns :exec\n" .
            "INSERT INTO products (name, price, status) VALUES (:name, :price, :status);"
        );
        $this->assertStringContainsString("string \$status = 'active'", $code);
    }

    public function test_individual_method_int_default(): void
    {
        $code = $this->methodSig(
            "-- @name CreateProduct\n-- @class Products\n-- @returns :exec\n" .
            "INSERT INTO products (name, price, stock) VALUES (:name, :price, :stock);"
        );
        $this->assertStringContainsString('int $stock = 0', $code);
    }

    public function test_individual_method_sql_function_becomes_nullable(): void
    {
        $code = $this->methodSig(
            "-- @name CreateProduct\n-- @class Products\n-- @returns :exec\n" .
            "INSERT INTO products (name, price, created_at) VALUES (:name, :price, :created_at);"
        );
        $this->assertStringContainsString('\DateTimeImmutable $created_at = null', $code);
    }

    // =========================================================================
    // SELECT WHERE — schema defaults must NOT apply
    // =========================================================================

    public function test_select_where_param_no_default_applied(): void
    {
        $code = $this->methodSig(
            "-- @name GetByStatus\n-- @class Products\n-- @returns :many\n" .
            "SELECT products.* FROM products WHERE status = :status;"
        );
        // status in WHERE should be required — not = 'active'
        $this->assertStringNotContainsString("'active'", $code);
        $this->assertStringContainsString('string $status', $code);
        $this->assertStringNotContainsString('$status =', $code);
    }

    public function test_select_where_int_param_no_default(): void
    {
        $code = $this->methodSig(
            "-- @name GetByStock\n-- @class Products\n-- @returns :many\n" .
            "SELECT products.* FROM products WHERE stock = :stock;"
        );
        $this->assertStringContainsString('int $stock', $code);
        $this->assertStringNotContainsString('$stock = 0', $code);
    }

    public function test_nullable_column_in_select_stays_required(): void
    {
        // description is NULL in schema — in WHERE it should still be required
        // (developer explicitly filtering by description value, not using schema default)
        $code = $this->methodSig(
            "-- @name GetByDesc\n-- @class Products\n-- @returns :many\n" .
            "SELECT products.* FROM products WHERE description = :description;"
        );
        // No = null default in WHERE context
        $this->assertStringNotContainsString('$description = null', $code);
    }
}
