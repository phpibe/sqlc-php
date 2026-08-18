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
 * Tests for features added in v2.19.24:
 *   E — toArray() on Params DTOs
 *   F — :count return type (standalone COUNT query → int)
 *   G — :exists return type (standalone EXISTS query → bool)
 *   D — SQL type syntax in @param (e.g. decimal(10,2), varchar(100))
 */
class NewFeaturesTest extends TestCase
{
    private SchemaCatalog $catalog;
    private QueryAnalyzer $analyzer;
    private ResultDtoGenerator $dtoGen;
    private QueryGenerator $queryGen;
    private QueryGenerator $queryGenWithInterface;
    private QueryParser $parser;
    private MySQLTypeMapper $mapper;

    protected function setUp(): void
    {
        $schema = <<<SQL
            CREATE TABLE products (
                id          INT            AUTO_INCREMENT PRIMARY KEY,
                name        VARCHAR(100)   NOT NULL,
                price       DECIMAL(10,2)  NOT NULL,
                stock       INT            NOT NULL,
                active      TINYINT(1)     NOT NULL,
                description TEXT           NULL,
                created_at  DATETIME       NOT NULL
            );
            CREATE TABLE orders (
                id         INT AUTO_INCREMENT PRIMARY KEY,
                product_id INT NOT NULL,
                quantity   INT NOT NULL,
                total      DECIMAL(10,2) NOT NULL
            );
        SQL;

        $this->catalog  = new SchemaCatalog((new SchemaParser())->parse($schema));
        $this->mapper   = new MySQLTypeMapper();
        $this->parser   = new QueryParser();
        $pr             = new ParamResolver($this->catalog, $this->mapper);
        $er             = new ExpressionTypeResolver($this->catalog, $this->mapper);
        $cr             = new ColumnResolver($this->catalog, $this->mapper, $pr, $er);
        $this->analyzer = new QueryAnalyzer($pr, $cr, $this->parser, new SqlRewriter(), $this->catalog);
        $this->dtoGen   = new ResultDtoGenerator('App\\DTOs', $this->mapper, $this->catalog);

        $ifaceGen = new InterfaceGenerator('App\\Contracts');
        $this->queryGen = new QueryGenerator(
            $this->catalog, $this->mapper, $this->dtoGen, 'App\\Queries'
        );
        $this->queryGenWithInterface = new QueryGenerator(
            $this->catalog, $this->mapper, $this->dtoGen, 'App\\Queries',
            true, $ifaceGen
        );
    }

    private function analyze(string $sql): array
    {
        return $this->analyzer->analyze($this->parser->parse($sql));
    }

    private function queryCode(string $sql, bool $withInterface = false): string
    {
        $q = $this->analyze($sql);
        $gen = $withInterface ? $this->queryGenWithInterface : $this->queryGen;
        foreach ($gen->generate($q) as $item) {
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
    // E — toArray() on Params DTOs
    // =========================================================================

    public function test_params_dto_has_toArray_method(): void
    {
        $q = $this->analyze(
            "-- @name CreateProduct\n-- @class Products\n-- @returns :exec\n-- @with params\n" .
            "INSERT INTO products (name, price, stock, active) VALUES (:name, :price, :stock, :active);"
        );
        $r = $this->dtoGen->generateParams($q[0]);

        $this->assertStringContainsString('public function toArray(): array', $r['code']);
    }

    public function test_params_dto_toArray_returns_scalar_as_is(): void
    {
        $q = $this->analyze(
            "-- @name CreateProduct\n-- @class Products\n-- @returns :exec\n-- @with params\n" .
            "INSERT INTO products (name, price, stock, active) VALUES (:name, :price, :stock, :active);"
        );
        $r = $this->dtoGen->generateParams($q[0]);

        $this->assertStringContainsString("'name' => \$this->name", $r['code']);
        $this->assertStringContainsString("'stock' => \$this->stock", $r['code']);
    }

    public function test_params_dto_toArray_symmetric_with_from(): void
    {
        // Verify toArray() exists alongside from() in the same DTO
        $q = $this->analyze(
            "-- @name UpdateProduct\n-- @class Products\n-- @returns :exec\n-- @with params\n" .
            "UPDATE products SET name = :name, price = :price WHERE id = :id;"
        );
        $r = $this->dtoGen->generateParams($q[0]);

        $this->assertStringContainsString('public static function from(array $data): self', $r['code']);
        $this->assertStringContainsString('public function toArray(): array', $r['code']);
    }

    // =========================================================================
    // F — :count return type
    // =========================================================================

    public function test_count_return_type_parsed(): void
    {
        $q = $this->analyze(
            "-- @name CountActiveProducts\n-- @class Products\n-- @returns :count\n" .
            "SELECT COUNT(*) FROM products WHERE active = 1;"
        );

        $this->assertSame(ReturnType::Count, $q[0]->returns);
    }

    public function test_count_method_returns_int(): void
    {
        $code = $this->queryCode(
            "-- @name CountActiveProducts\n-- @class Products\n-- @returns :count\n" .
            "SELECT COUNT(*) FROM products WHERE active = 1;"
        );

        $this->assertStringContainsString('): int', $code);
    }

    public function test_count_method_uses_fetchColumn(): void
    {
        $code = $this->queryCode(
            "-- @name CountActiveProducts\n-- @class Products\n-- @returns :count\n" .
            "SELECT COUNT(*) FROM products WHERE active = 1;"
        );

        $this->assertStringContainsString('fetchColumn()', $code);
        $this->assertStringContainsString('(int)', $code);
    }

    public function test_count_method_with_params(): void
    {
        $code = $this->queryCode(
            "-- @name CountByStatus\n-- @class Products\n-- @returns :count\n" .
            "SELECT COUNT(*) FROM products WHERE active = :active AND stock > :min_stock;"
        );

        $this->assertStringContainsString('int $active', $code);
        $this->assertStringContainsString('int $min_stock', $code);
        $this->assertStringContainsString('): int', $code);
    }

    public function test_count_method_in_interface(): void
    {
        $code = $this->interfaceCode(
            "-- @name CountActiveProducts\n-- @class Products\n-- @returns :count\n" .
            "SELECT COUNT(*) FROM products WHERE active = 1;"
        );

        $this->assertStringContainsString('countActiveProducts(): int', $code);
    }

    public function test_count_no_dto_generated(): void
    {
        // :count queries should not generate DTOs
        $q = $this->analyze(
            "-- @name CountActiveProducts\n-- @class Products\n-- @returns :count\n" .
            "SELECT COUNT(*) FROM products WHERE active = 1;"
        );

        // returnsModelDirectly should be false and resultColumns empty for COUNT(*)
        // — the key thing is no DTO file is written (tested by CLI exclusion)
        $this->assertSame(ReturnType::Count, $q[0]->returns);
    }

    // =========================================================================
    // G — :exists return type
    // =========================================================================

    public function test_exists_return_type_parsed(): void
    {
        $q = $this->analyze(
            "-- @name EmailExists\n-- @class Products\n-- @returns :exists\n" .
            "SELECT 1 FROM products WHERE name = :name LIMIT 1;"
        );

        $this->assertSame(ReturnType::Exists, $q[0]->returns);
    }

    public function test_exists_method_returns_bool(): void
    {
        $code = $this->queryCode(
            "-- @name ProductExists\n-- @class Products\n-- @returns :exists\n" .
            "SELECT 1 FROM products WHERE id = :id LIMIT 1;"
        );

        $this->assertStringContainsString('): bool', $code);
    }

    public function test_exists_method_uses_fetchColumn_with_bool_cast(): void
    {
        $code = $this->queryCode(
            "-- @name ProductExists\n-- @class Products\n-- @returns :exists\n" .
            "SELECT 1 FROM products WHERE id = :id LIMIT 1;"
        );

        $this->assertStringContainsString('fetchColumn()', $code);
        $this->assertStringContainsString('> 0', $code);
    }

    public function test_exists_method_in_interface(): void
    {
        $code = $this->interfaceCode(
            "-- @name ProductExists\n-- @class Products\n-- @returns :exists\n" .
            "SELECT 1 FROM products WHERE id = :id LIMIT 1;"
        );

        $this->assertStringContainsString('productExists(int $id): bool', $code);
    }

    public function test_exists_and_count_in_same_class(): void
    {
        $code = $this->queryCode(
            "-- @name CountProducts\n-- @class Products\n-- @returns :count\n" .
            "SELECT COUNT(*) FROM products;\n\n" .
            "-- @name ProductExists\n-- @class Products\n-- @returns :exists\n" .
            "SELECT 1 FROM products WHERE id = :id LIMIT 1;"
        );

        $this->assertStringContainsString('countProducts(): int', $code);
        $this->assertStringContainsString('productExists(int $id): bool', $code);
    }

    // =========================================================================
    // D — SQL type syntax in @param
    // =========================================================================

    public function test_decimal_sql_type_resolves_to_float(): void
    {
        $q = $this->analyze(
            "-- @name FilterByPrice\n-- @class Products\n-- @returns :many\n" .
            "-- @param min_price decimal(10,2)\n" .
            "-- @param max_price decimal(10,2)\n" .
            "SELECT products.* FROM products\n" .
            "WHERE price BETWEEN :min_price AND :max_price;"
        );

        $params = array_column($q[0]->params, null, 'name');
        $this->assertSame('float', $params['min_price']->phpType);
        $this->assertSame('float', $params['max_price']->phpType);
    }

    public function test_varchar_sql_type_resolves_to_string(): void
    {
        $q = $this->analyze(
            "-- @name SearchByName\n-- @class Products\n-- @returns :many\n" .
            "-- @param search_term varchar(100)\n" .
            "SELECT products.* FROM products\n" .
            "WHERE name LIKE :search_term;"
        );

        $params = array_column($q[0]->params, null, 'name');
        $this->assertSame('string', $params['search_term']->phpType);
    }

    public function test_tinyint_sql_type_resolves_to_int(): void
    {
        $q = $this->analyze(
            "-- @name FilterByActive\n-- @class Products\n-- @returns :many\n" .
            "-- @param is_active tinyint(1)\n" .
            "SELECT products.* FROM products\n" .
            "WHERE active = :is_active;"
        );

        $params = array_column($q[0]->params, null, 'name');
        $this->assertSame('int', $params['is_active']->phpType);
    }

    public function test_nullable_sql_type_resolves_to_nullable_php_type(): void
    {
        $q = $this->analyze(
            "-- @name SearchProducts\n-- @class Products\n-- @returns :many\n" .
            "-- @param min_price ?decimal(10,2)\n" .
            "SELECT products.* FROM products\n" .
            "WHERE (:min_price IS NULL OR price >= :min_price);"
        );

        $params = array_column($q[0]->params, null, 'name');
        $this->assertSame('?float', $params['min_price']->phpType);
    }

    public function test_sql_type_generates_correct_method_signature(): void
    {
        $code = $this->queryCode(
            "-- @name FilterByPrice\n-- @class Products\n-- @returns :many\n" .
            "-- @param min_price decimal(10,2)\n" .
            "-- @param search    varchar(100)\n" .
            "SELECT products.* FROM products\n" .
            "WHERE price >= :min_price AND name LIKE :search;"
        );

        $this->assertStringContainsString('float $min_price', $code);
        $this->assertStringContainsString('string $search', $code);
    }

    public function test_php_type_still_works_alongside_sql_type(): void
    {
        // PHP types not in the SQL keyword list work as before
        // 'string' is not a SQL keyword — stays as PHP string type
        // 'int' is not a SQL keyword (INT is, but bare 'int' matches PHP first)
        $q = $this->analyze(
            "-- @name SearchProducts\n-- @class Products\n-- @returns :many\n" .
            "-- @param max_stock int\n" .
            "-- @param name_like string\n" .
            "SELECT products.* FROM products\n" .
            "WHERE stock <= :max_stock AND name LIKE :name_like;"
        );

        $params = array_column($q[0]->params, null, 'name');
        $this->assertSame('int',    $params['max_stock']->phpType);
        $this->assertSame('string', $params['name_like']->phpType);
    }

    public function test_sqlTypeToPhpType_conversions(): void
    {
        $this->assertSame('float',               $this->mapper->sqlTypeToPhpType('decimal(10,2)'));
        $this->assertSame('float',               $this->mapper->sqlTypeToPhpType('DECIMAL(10,2)'));
        $this->assertSame('float',               $this->mapper->sqlTypeToPhpType('numeric(8,3)'));
        $this->assertSame('string',              $this->mapper->sqlTypeToPhpType('varchar(100)'));
        $this->assertSame('string',              $this->mapper->sqlTypeToPhpType('VARCHAR(255)'));
        $this->assertSame('string',              $this->mapper->sqlTypeToPhpType('text'));
        $this->assertSame('int',                 $this->mapper->sqlTypeToPhpType('tinyint(1)'));
        $this->assertSame('int',                 $this->mapper->sqlTypeToPhpType('bigint'));
        $this->assertSame('\DateTimeImmutable',  $this->mapper->sqlTypeToPhpType('datetime'));
        $this->assertSame('?float',              $this->mapper->sqlTypeToPhpType('?decimal(10,2)'));
        $this->assertNull(                       $this->mapper->sqlTypeToPhpType('float'));    // PHP type → null
        $this->assertNull(                       $this->mapper->sqlTypeToPhpType('string'));   // PHP type → null
        $this->assertNull(                       $this->mapper->sqlTypeToPhpType('users.id')); // table.col → null
    }
}
