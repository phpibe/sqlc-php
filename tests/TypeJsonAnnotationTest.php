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
 * Tests for unified @type json:Class syntax (v2.16.0).
 *
 * @type alias json:Class       → Class   (one, non-nullable)
 * @type alias ?json:Class      → ?Class  (one, nullable)
 * @type alias json:Class[]     → Class[] (many, non-nullable)
 * @type alias ?json:Class[]    → Class[]|null (many, nullable)
 *
 * The legacy @json / @json:one / @json:many syntax continues to work
 * for backward compatibility — it populates the same jsonColumns array.
 */
class TypeJsonAnnotationTest extends TestCase
{
    private SchemaCatalog      $catalog;
    private MySQLTypeMapper    $mapper;
    private QueryParser        $parser;
    private QueryAnalyzer      $analyzer;
    private QueryGenerator     $queryGen;
    private ResultDtoGenerator $dtoGen;

    protected function setUp(): void
    {
        $schema = <<<SQL
            CREATE TABLE countries (
                id         INT AUTO_INCREMENT PRIMARY KEY,
                name       VARCHAR(100) NOT NULL,
                code       CHAR(2)      NOT NULL
            );
            CREATE TABLE cities (
                id         INT AUTO_INCREMENT PRIMARY KEY,
                country_id INT NOT NULL,
                name       VARCHAR(100) NOT NULL,
                population INT NOT NULL DEFAULT 0
            );
            CREATE TABLE orders (
                id         INT AUTO_INCREMENT PRIMARY KEY,
                user_id    INT NOT NULL,
                total      DECIMAL(10,2) NOT NULL,
                status     VARCHAR(20)   NOT NULL DEFAULT 'pending'
            );
            CREATE TABLE addresses (
                id         INT AUTO_INCREMENT PRIMARY KEY,
                user_id    INT NOT NULL,
                street     VARCHAR(200) NOT NULL,
                city       VARCHAR(100) NOT NULL
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
        $this->dtoGen   = $dtoGen;
        $this->queryGen = new QueryGenerator($this->catalog, $this->mapper, $dtoGen, 'App\\Queries');
    }

    private function parse(string $sql): array
    {
        return $this->parser->parse($sql);
    }

    private function dtoCode(string $sql): string
    {
        $queries = $this->analyzer->analyze($this->parser->parse($sql));
        $query   = $queries[0];
        $result  = $this->dtoGen->generate($query);
        return $result['code'];
    }

    // =========================================================================
    // Parser — @type json: syntax populates jsonColumns
    // =========================================================================

    public function test_parser_type_json_many_populates_json_columns(): void
    {
        $q = $this->parse(<<<SQL
            -- @name GetCountry
            -- @class Country
            -- @returns :one
            -- @type cities json:City[]
            SELECT countries.id, JSON_ARRAYAGG(JSON_OBJECT('id', cities.id)) AS cities
            FROM countries JOIN cities ON cities.country_id = countries.id
            WHERE countries.id = :id GROUP BY countries.id;
        SQL);

        $this->assertArrayHasKey('cities', $q[0]->jsonColumns);
        $this->assertSame('City',  $q[0]->jsonColumns['cities']['class']);
        $this->assertTrue($q[0]->jsonColumns['cities']['many']);
        $this->assertFalse($q[0]->jsonColumns['cities']['nullable']);
    }

    public function test_parser_type_json_one_populates_json_columns(): void
    {
        $q = $this->parse(<<<SQL
            -- @name GetOrder
            -- @class Order
            -- @returns :one
            -- @type address json:Address
            SELECT orders.id, JSON_OBJECT('street', addresses.street) AS address
            FROM orders JOIN addresses ON addresses.user_id = orders.user_id
            WHERE orders.id = :id;
        SQL);

        $this->assertArrayHasKey('address', $q[0]->jsonColumns);
        $this->assertSame('Address', $q[0]->jsonColumns['address']['class']);
        $this->assertFalse($q[0]->jsonColumns['address']['many']);
        $this->assertFalse($q[0]->jsonColumns['address']['nullable']);
    }

    public function test_parser_type_nullable_json_many(): void
    {
        $q = $this->parse(<<<SQL
            -- @name GetCountry
            -- @class Country
            -- @returns :one
            -- @type cities ?json:City[]
            SELECT countries.id, JSON_ARRAYAGG(JSON_OBJECT('id', cities.id)) AS cities
            FROM countries LEFT JOIN cities ON cities.country_id = countries.id
            WHERE countries.id = :id GROUP BY countries.id;
        SQL);

        $this->assertTrue($q[0]->jsonColumns['cities']['many']);
        $this->assertTrue($q[0]->jsonColumns['cities']['nullable']);
    }

    public function test_parser_type_nullable_json_one(): void
    {
        $q = $this->parse(<<<SQL
            -- @name GetOrder
            -- @class Order
            -- @returns :one
            -- @type address ?json:Address
            SELECT orders.id, JSON_OBJECT('street', addresses.street) AS address
            FROM orders LEFT JOIN addresses ON addresses.user_id = orders.user_id
            WHERE orders.id = :id;
        SQL);

        $this->assertFalse($q[0]->jsonColumns['address']['many']);
        $this->assertTrue($q[0]->jsonColumns['address']['nullable']);
    }

    public function test_parser_type_json_does_not_pollute_type_overrides(): void
    {
        // json:Class entries must NOT appear in typeOverrides — they go to jsonColumns
        $q = $this->parse(<<<SQL
            -- @name GetCountry
            -- @class Country
            -- @returns :one
            -- @type cities json:City[]
            -- @type total  float
            SELECT countries.id, JSON_ARRAYAGG(JSON_OBJECT('id', cities.id)) AS cities,
                   SUM(cities.population) AS total
            FROM countries JOIN cities ON cities.country_id = countries.id
            WHERE countries.id = :id GROUP BY countries.id;
        SQL);

        $this->assertArrayHasKey('cities', $q[0]->jsonColumns);
        $this->assertArrayNotHasKey('cities', $q[0]->typeOverrides);
        $this->assertArrayHasKey('total', $q[0]->typeOverrides);
        $this->assertSame('float', $q[0]->typeOverrides['total']);
    }

    public function test_parser_mixed_type_json_and_scalar_on_same_query(): void
    {
        $q = $this->parse(<<<SQL
            -- @name GetCountry
            -- @class Country
            -- @returns :one
            -- @type cities  json:City[]
            -- @type address ?json:Address
            -- @type total   int
            SELECT countries.id, 'x' AS cities, 'y' AS address, 1 AS total
            FROM countries WHERE countries.id = :id;
        SQL);

        $this->assertArrayHasKey('cities',  $q[0]->jsonColumns);
        $this->assertArrayHasKey('address', $q[0]->jsonColumns);
        $this->assertSame('int', $q[0]->typeOverrides['total'] ?? null);
        $this->assertArrayNotHasKey('cities',  $q[0]->typeOverrides);
        $this->assertArrayNotHasKey('address', $q[0]->typeOverrides);
    }

    // =========================================================================
    // Legacy @json syntax still works (backward compat)
    // =========================================================================

    public function test_legacy_json_annotation_still_works(): void
    {
        $q = $this->parse(<<<SQL
            -- @name GetCountry
            -- @class Country
            -- @returns :one
            -- @json cities City
            SELECT countries.id, JSON_ARRAYAGG(JSON_OBJECT('id', cities.id)) AS cities
            FROM countries JOIN cities ON cities.country_id = countries.id
            WHERE countries.id = :id GROUP BY countries.id;
        SQL);

        $this->assertArrayHasKey('cities', $q[0]->jsonColumns);
        $this->assertSame('City', $q[0]->jsonColumns['cities']['class']);
        $this->assertTrue($q[0]->jsonColumns['cities']['many']);
        $this->assertFalse($q[0]->jsonColumns['cities']['nullable']);
    }

    public function test_legacy_json_one_annotation_still_works(): void
    {
        $q = $this->parse(<<<SQL
            -- @name GetOrder
            -- @class Order
            -- @returns :one
            -- @json:one address Address
            SELECT orders.id, JSON_OBJECT('street', 'x') AS address
            FROM orders WHERE orders.id = :id;
        SQL);

        $this->assertFalse($q[0]->jsonColumns['address']['many']);
        $this->assertFalse($q[0]->jsonColumns['address']['nullable']);
    }

    // =========================================================================
    // Generated DTO — property types and fromRow casts
    // =========================================================================

    public function test_dto_json_many_non_nullable_generates_array_property(): void
    {
        $code = $this->dtoCode(<<<SQL
            -- @name GetCountry
            -- @class Country
            -- @returns :one
            -- @type cities json:City[]
            SELECT countries.id, countries.name,
                   JSON_ARRAYAGG(JSON_OBJECT('id', cities.id, 'name', cities.name)) AS cities
            FROM countries JOIN cities ON cities.country_id = countries.id
            WHERE countries.id = :id GROUP BY countries.id;
        SQL);

        $this->assertStringContainsString('/** @var City[] */', $code);
        $this->assertStringContainsString('public array $cities', $code);
        $this->assertStringContainsString('array_map(fn(array $r) => City::fromRow($r)', $code);
        $this->assertStringContainsString('json_decode', $code);
        // Non-nullable: no null check
        $this->assertStringNotContainsString('$cities !== null', $code);
    }

    public function test_dto_json_many_nullable_generates_nullable_array(): void
    {
        $code = $this->dtoCode(<<<SQL
            -- @name GetCountry
            -- @class Country
            -- @returns :one
            -- @type cities ?json:City[]
            SELECT countries.id, countries.name,
                   JSON_ARRAYAGG(JSON_OBJECT('id', cities.id, 'name', cities.name)) AS cities
            FROM countries LEFT JOIN cities ON cities.country_id = countries.id
            WHERE countries.id = :id GROUP BY countries.id;
        SQL);

        $this->assertStringContainsString('/** @var City[]|null */', $code);
        $this->assertStringContainsString('public ?array $cities', $code);
        // Nullable: has null check in fromRow
        $this->assertStringContainsString('isset($row[\'cities\'])', $code);
    }

    public function test_dto_json_one_non_nullable_generates_typed_property(): void
    {
        $code = $this->dtoCode(<<<SQL
            -- @name GetOrder
            -- @class Order
            -- @returns :one
            -- @type address json:Address
            SELECT orders.id, orders.total,
                   JSON_OBJECT('street', addresses.street, 'city', addresses.city) AS address
            FROM orders JOIN addresses ON addresses.user_id = orders.user_id
            WHERE orders.id = :id;
        SQL);

        $this->assertStringContainsString('public Address $address', $code);
        $this->assertStringContainsString('Address::fromRow(json_decode', $code);
        $this->assertStringNotContainsString('?Address', $code);
    }

    public function test_dto_json_one_nullable_generates_nullable_property(): void
    {
        $code = $this->dtoCode(<<<SQL
            -- @name GetOrder
            -- @class Order
            -- @returns :one
            -- @type address ?json:Address
            SELECT orders.id, orders.total,
                   JSON_OBJECT('street', addresses.street, 'city', addresses.city) AS address
            FROM orders LEFT JOIN addresses ON addresses.user_id = orders.user_id
            WHERE orders.id = :id;
        SQL);

        $this->assertStringContainsString('public ?Address $address', $code);
        $this->assertStringContainsString('isset($row[\'address\'])', $code);
        $this->assertStringContainsString('Address::fromRow(json_decode', $code);
    }

    // =========================================================================
    // Equivalence: @type json:Class[] == @json ClassName (legacy)
    // =========================================================================

    public function test_new_syntax_equivalent_to_legacy_for_many(): void
    {
        $newSyntax = $this->parse(<<<SQL
            -- @name GetCountry
            -- @class Country
            -- @returns :one
            -- @type cities json:City[]
            SELECT countries.id, 'x' AS cities FROM countries WHERE countries.id = :id;
        SQL);

        $legacy = $this->parse(<<<SQL
            -- @name GetCountry
            -- @class Country
            -- @returns :one
            -- @json cities City
            SELECT countries.id, 'x' AS cities FROM countries WHERE countries.id = :id;
        SQL);

        $this->assertSame($newSyntax[0]->jsonColumns['cities']['class'],    $legacy[0]->jsonColumns['cities']['class']);
        $this->assertSame($newSyntax[0]->jsonColumns['cities']['many'],     $legacy[0]->jsonColumns['cities']['many']);
    }

    public function test_new_syntax_equivalent_to_legacy_for_one(): void
    {
        $newSyntax = $this->parse(<<<SQL
            -- @name GetOrder
            -- @class Order
            -- @returns :one
            -- @type address json:Address
            SELECT orders.id, 'x' AS address FROM orders WHERE orders.id = :id;
        SQL);

        $legacy = $this->parse(<<<SQL
            -- @name GetOrder
            -- @class Order
            -- @returns :one
            -- @json:one address Address
            SELECT orders.id, 'x' AS address FROM orders WHERE orders.id = :id;
        SQL);

        $this->assertSame($newSyntax[0]->jsonColumns['address']['class'],   $legacy[0]->jsonColumns['address']['class']);
        $this->assertSame($newSyntax[0]->jsonColumns['address']['many'],    $legacy[0]->jsonColumns['address']['many']);
    }

    // =========================================================================
    // Scalar @type unaffected
    // =========================================================================

    public function test_scalar_type_override_unaffected(): void
    {
        $q = $this->parse(<<<SQL
            -- @name GetStats
            -- @class Country
            -- @returns :one
            -- @type total int
            -- @type score ?float
            SELECT COUNT(*) AS total, AVG(population) AS score FROM cities;
        SQL);

        $this->assertSame('int',    $q[0]->typeOverrides['total']);
        $this->assertSame('?float', $q[0]->typeOverrides['score']);
        $this->assertEmpty($q[0]->jsonColumns);
    }
}
